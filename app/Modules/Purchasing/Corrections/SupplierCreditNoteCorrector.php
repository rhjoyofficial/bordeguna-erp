<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordSupplierCreditNote;
use App\Modules\Purchasing\Actions\ReverseSupplierCreditNote;
use App\Modules\Purchasing\Corrections\Concerns\CorrectsPurchasingDocuments;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A supplier credit note's own reversal and successor (corrections wave 1,
 * 20 Oct 2026, FINAL-PLAN 3.3).
 *
 *   reverse()          ReverseSupplierCreditNote (PUR-003) on the date the date
 *                      policy gave: Dr GRNI / Cr Accounts Payable, the bills it
 *                      credited owed again, the return creditable again
 *   createSuccessor()  RecordSupplierCreditNote for the SAME return, with the
 *                      edited number, date, amount and bills; the return's cap
 *                      (C-SCN-2) and every other rule apply again
 *   markOriginal()     done by the reversal; the number is free (PUR-004)
 */
class SupplierCreditNoteCorrector implements DocumentCorrector
{
    use CorrectsPurchasingDocuments;

    public function __construct(
        private readonly SupplierCreditNoteActionPolicy $policy,
        private readonly ReverseSupplierCreditNote $reverseCreditNote,
        private readonly RecordSupplierCreditNote $recordCreditNote,
        private readonly PostPurchaseJournals $posting,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SupplierCreditNote::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $note = SupplierCreditNote::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournals($note);
        PurchaseReturn::query()->whereKey($note->purchase_return_id)->lockForUpdate()->get(['id']);
        SupplierBill::query()->whereIn('id', $note->allocations()->pluck('supplier_bill_id'))->lockForUpdate()->get(['id']);

        return $note->load('allocations');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SupplierCreditNote $document */
        return hash('sha256', (string) json_encode([
            'n' => [
                $document->id, $document->supplier_id, $document->purchase_return_id, $document->credit_note_number, $document->credit_date?->toDateString(),
                (string) $document->total, (string) $document->tax_total, $document->note, $document->reversed_at?->toIso8601String(),
            ],
            'allocations' => $document->allocations()->orderBy('id')->get(['supplier_bill_id', 'amount'])->map(fn ($a) => [(int) $a->supplier_bill_id, (string) $a->amount])->all(),
            'journals' => $this->journalTrail($document),
            'period' => $this->periodBoundary(),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var SupplierCreditNote $document */
        $this->refuseWhenDone($document);

        $data = Validator::make($input, [
            'credit_note_number' => ['required', 'string', 'max:100'],
            'credit_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
        ], [
            'allocations.required' => 'Say which bills this credit reduces, and by how much each.',
        ])->validate();

        $number = trim($data['credit_note_number']);
        $taken = SupplierCreditNote::query()->where('supplier_id', $document->supplier_id)->where('credit_note_number', $number)
            ->whereNull('reversed_at')->whereKeyNot($document->id)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['credit_note_number' => "Credit note {$number} has already been recorded for this supplier."]);
        }

        $own = $document->allocations->groupBy('supplier_bill_id')
            ->map(fn ($rows) => $rows->sum(fn ($a) => Money::ofTaka((string) $a->amount)->minor))->all();

        $rows = [];
        $total = 0;
        $claimed = [];

        foreach (array_values($data['allocations']) as $index => $row) {
            $amount = Money::ofTaka((string) $row['amount'])->minor;

            if ($amount <= 0) {
                continue;
            }

            $bill = SupplierBill::query()->find((int) $row['supplier_bill_id']);

            if ($bill === null || (int) $bill->supplier_id !== (int) $document->supplier_id || $bill->isReversed()) {
                throw ValidationException::withMessages(["allocations.{$index}" => 'Line '.($index + 1).' is not a live bill of this supplier.']);
            }

            $open = $bill->outstanding()->minor + ($own[$bill->id] ?? 0) - ($claimed[$bill->id] ?? 0);
            $claimed[$bill->id] = ($claimed[$bill->id] ?? 0) + $amount;

            if ($amount > $open) {
                throw ValidationException::withMessages(["allocations.{$index}" => "Bill {$bill->bill_number} has only ".Tk::of(max(0, $open)).' outstanding.']);
            }

            $total += $amount;
            $rows[] = ['supplier_bill_id' => $bill->id, 'amount' => Money::ofMinor($amount)->toTaka()];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['allocations' => 'Say which bills this credit reduces, and by how much each.']);
        }

        $tax = Money::ofTaka((string) ($data['tax_amount'] ?? $document->tax_total ?? '0'))->minor;

        // C-SCN-2: the return's value, less what other live notes credit.
        $return = PurchaseReturn::query()->findOrFail($document->purchase_return_id);
        $creditable = $return->invoiceableValue()->minor - $return->creditedTotal()->minor + Money::ofTaka((string) $document->total)->minor;

        if ($total - $tax > $creditable) {
            throw ValidationException::withMessages(['allocations' => "{$return->label()} has only ".Tk::of(max(0, $creditable)).' to credit.']);
        }

        $date = Carbon::parse($data['credit_date'])->startOfDay();
        $normalised = [
            'credit_note_number' => $number,
            'credit_date' => $date->toDateString(),
            'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
            'tax_amount' => Money::ofMinor($tax)->toTaka(),
            'allocations' => $rows,
        ];

        $before = [
            $document->credit_note_number, $document->credit_date?->toDateString(), $document->note ?: null, Money::ofTaka((string) $document->tax_total)->toTaka(),
            $document->allocations->map(fn ($a) => [(int) $a->supplier_bill_id, Money::ofTaka((string) $a->amount)->minor])->sort()->values()->all(),
        ];
        $after = [
            $number, $normalised['credit_date'], $normalised['note'], $normalised['tax_amount'],
            collect($rows)->map(fn ($a) => [(int) $a['supplier_bill_id'], Money::ofTaka($a['amount'])->minor])->sort()->values()->all(),
        ];

        if ($before === $after) {
            throw ValidationException::withMessages(['allocations' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        return new ReplacementData($date, $total, $normalised, $input);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SupplierCreditNote $document */
        $document->loadMissing(['supplier:id,name', 'allocations.bill:id,bill_number']);
        $total = $this->totalMinor($document);
        $supplier = $document->supplier?->name ?? 'the supplier';
        $bills = $document->allocations->map(fn ($a) => $a->bill?->bill_number)->filter()->unique()->implode(', ');

        $lines = [
            new EffectLine(Tk::of($total)." goes back on Accounts Payable for {$supplier} on ".Tk::date($dates->reversalDate).($bills === '' ? '.' : ", and bill {$bills} is owed again."), 'Accounts Payable', -$total, 'ledger'),
        ];

        if ($replacement !== null) {
            $newBills = SupplierBill::query()->whereIn('id', array_column($replacement->values['allocations'], 'supplier_bill_id'))->pluck('bill_number')->implode(', ');
            $lines[] = new EffectLine("Credit note {$replacement->values['credit_note_number']} of ".Tk::of($replacement->totalMinor)." reduces bill {$newBills} from ".Tk::date($replacement->date).'.', 'Accounts Payable', $replacement->totalMinor, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The corrected credit note is a new record, labelled \"Correction of {$number}\"."]
            : ["{$number} stays, marked Reversed, with the bills it had credited.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return $this->makerAndLastEditor($document);
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var SupplierCreditNote $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SupplierCreditNote $document */
        try {
            $reversed = $this->reverseCreditNote->handle($document, $ctx->actor, $ctx->reason->display(), true, $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $reversed->reversal_journal_id === null ? null : Journal::query()->find($reversed->reversal_journal_id);
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var SupplierCreditNote $document */
        $values = $replacement->values;

        try {
            return $this->recordCreditNote->handle(
                supplier: $document->supplier()->firstOrFail(),
                purchaseReturn: PurchaseReturn::query()->findOrFail($document->purchase_return_id),
                creditNoteNumber: $values['credit_note_number'],
                creditDate: $values['credit_date'],
                allocations: array_map(fn (array $row) => [
                    'bill' => SupplierBill::query()->findOrFail($row['supplier_bill_id']),
                    'amount' => $row['amount'],
                ], $values['allocations']),
                createdBy: $ctx->actor,
                note: $values['note'],
                taxAmount: $values['tax_amount'],
                pass: $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['credit_date' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var SupplierCreditNote $successor */
        return $this->posting->existingJournal($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseSupplierCreditNote wrote the Reversible columns with the reason.
    }

    public function successorClass(): string
    {
        return SupplierCreditNote::class;
    }

    /**
     * This type's own invariant: the original is marked and credits nothing;
     * the corrected note's allocations add up to it and its journal debits
     * Accounts Payable by exactly its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SupplierCreditNote $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$this->policy->numberOf($original)} was not marked.");
        }

        if (SupplierCreditNoteAllocation::query()->where('supplier_credit_note_id', $original->id)->exists()) {
            throw new LogicException("{$this->policy->numberOf($original)} still credits a bill.");
        }

        if ($successor === null) {
            return;
        }

        /** @var SupplierCreditNote $successor */
        $total = Money::ofTaka((string) $successor->total)->minor;
        $allocated = Money::ofTaka((string) ($successor->allocations()->sum('amount') ?: '0'))->minor;

        if ($allocated !== $total) {
            throw new LogicException("{$this->policy->numberOf($successor)}'s allocations do not add up to it.");
        }

        $journal = $this->posting->existingJournal($successor);

        if ($journal !== null) {
            $payable = AccountMapping::resolve('purchasing.accounts_payable', Carbon::parse($journal->journal_date->toDateString()))->id;

            if ((int) $journal->lines()->where('account_id', $payable)->sum('amount_minor') !== $total) {
                throw new LogicException("{$this->policy->numberOf($successor)}'s total does not match its journal.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
