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
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\ReverseSupplierPayment;
use App\Modules\Purchasing\Corrections\Concerns\CorrectsPurchasingDocuments;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A supplier payment's own reversal and successor (corrections wave 1, 20
 * Oct 2026, FINAL-PLAN 3.3: "Supplier payment: Correct (date, amount, account,
 * allocation)").
 *
 *   reverse()          ReverseSupplierPayment (PUR-003) on the date the date
 *                      policy gave, with the reason: Dr the money account / Cr
 *                      Accounts Payable, the bills it paid open again, an
 *                      overpayment advance kept from it reversed with it
 *   createSuccessor()  RecordSupplierPayment with the edited form, every rule
 *                      of the payment screen again (outstanding caps, the money
 *                      account, Finance policy B14, TDS/VDS, the approval gate)
 *   markOriginal()     done by the reversal (the Reversible columns)
 *
 * ⚠ The supplier does not change: money paid to the wrong supplier is reversed
 * and paid again. A settlement discount is never taken in a correction; the
 * check refuses a payment that took one until the discount is reversed.
 */
class SupplierPaymentCorrector implements DocumentCorrector
{
    use CorrectsPurchasingDocuments;

    public function __construct(
        private readonly SupplierPaymentActionPolicy $policy,
        private readonly ReverseSupplierPayment $reversePayment,
        private readonly RecordSupplierPayment $recordPayment,
        private readonly PostPurchaseJournals $posting,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SupplierPayment::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $payment = SupplierPayment::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournals($payment);
        SupplierBill::query()->whereIn('id', $payment->allocations()->pluck('supplier_bill_id'))->lockForUpdate()->get(['id']);

        return $payment->load('allocations');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SupplierPayment $document */
        $advance = SupplierAdvance::query()->where('supplier_payment_id', $document->id)->first();

        return hash('sha256', (string) json_encode([
            'p' => [
                $document->id, $document->supplier_id, $document->money_account_id, $document->paid_on?->toDateString(), (string) $document->amount,
                (string) $document->tds_amount, (string) $document->vds_amount, $document->reference, $document->note, $document->voucher_id,
                $document->reversed_at?->toIso8601String(),
            ],
            'allocations' => $document->allocations()->orderBy('id')->get(['supplier_bill_id', 'amount'])->map(fn ($a) => [(int) $a->supplier_bill_id, (string) $a->amount])->all(),
            'advance' => $advance === null ? null : [$advance->id, $advance->reversed_at?->toIso8601String(), $advance->applications()->live()->count(), $advance->refunds()->live()->count()],
            'journals' => $this->journalTrail($document),
            'matched' => $this->bankMatches($document),
            'period' => $this->periodBoundary(),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var SupplierPayment $document */
        $this->refuseWhenDone($document);

        $data = Validator::make($input, [
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'money_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tds_rate_id' => ['nullable', 'integer'],
            'vds_rate_id' => ['nullable', 'integer'],
            'excess_to_advance' => ['nullable', 'boolean'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
        ], [
            'allocations.required' => 'Say which bills this payment settles, and how much of each.',
        ])->validate();

        $account = MoneyAccount::query()->find((int) $data['money_account_id']);

        if ($account === null || ! $account->is_active) {
            throw ValidationException::withMessages(['money_account_id' => 'Pick an active account to pay from.']);
        }

        // What this payment settled on each bill comes back when it is
        // reversed, so it may be paid again by the corrected payment.
        $own = $document->allocations->groupBy('supplier_bill_id')
            ->map(fn ($rows) => $rows->sum(fn ($a) => Money::ofTaka((string) $a->amount)->minor))->all();

        $rows = [];
        $total = 0;
        $excess = 0;
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
                if (! ($data['excess_to_advance'] ?? false)) {
                    throw ValidationException::withMessages(["allocations.{$index}" => "Bill {$bill->bill_number} has only ".Tk::of(max(0, $open)).' to pay.']);
                }

                $excess += $amount - max(0, $open);
            }

            $total += $amount;
            $rows[] = ['supplier_bill_id' => $bill->id, 'amount' => Money::ofMinor($amount)->toTaka()];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['allocations' => 'Say which bills this payment settles, and how much of each.']);
        }

        $date = Carbon::parse($data['paid_on'])->startOfDay();
        $normalised = [
            'paid_on' => $date->toDateString(),
            'money_account_id' => $account->id,
            'reference' => ($data['reference'] ?? '') === '' ? null : $data['reference'],
            'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
            'tds_rate_id' => array_key_exists('tds_rate_id', $data) ? ($data['tds_rate_id'] === null ? null : (int) $data['tds_rate_id']) : $document->tds_rate_id,
            'vds_rate_id' => array_key_exists('vds_rate_id', $data) ? ($data['vds_rate_id'] === null ? null : (int) $data['vds_rate_id']) : $document->vds_rate_id,
            'excess_to_advance' => (bool) ($data['excess_to_advance'] ?? false),
            'allocations' => $rows,
        ];

        if ($this->sameAs($document, $normalised)) {
            throw ValidationException::withMessages(['allocations' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        $reasons = [];

        if (PurchasingApprovalGate::enabled()) {
            $reasons = app(PurchasingApprovalGate::class)->assess(new PurchaseDraft(
                kind: 'payment',
                supplier: $document->supplier()->firstOrFail(),
                amount: Money::ofMinor($total),
                documentDate: $date,
                reference: $normalised['reference'],
                summary: "Corrected payment #{$document->id}",
                overApplication: $excess > 0 ? 'it pays '.Tk::of($excess).' more than the bills owe, kept as an advance' : null,
                moneyAccount: $account,
            ));

            $basis = $this->secondPerson->basis($this->totalMinor($document), $total);

            if ($reasons !== [] && ! $this->secondPerson->applies((int) $document->business_id, $basis)) {
                throw ValidationException::withMessages(['allocations' => 'The corrected payment needs a purchasing approval ('.implode('; ', array_column($reasons, 'label')).'). Reverse this payment and record the right one under Purchases, Payments, so it goes for approval.']);
            }
        }

        return new ReplacementData($date, $total, $normalised + ['approval_reasons' => array_column($reasons, 'label'), 'supplier_id' => (int) $document->supplier_id], $input);
    }

    /**
     * ⭐ B13 (24 Oct 2026): what the duplicate rule compares on a Correct (the
     * supplier stays the original's; the amount is the corrected total), so a
     * corrected payment that now looks like another recent one asks "Possible
     * duplicate" too. The original and its chain never count.
     */
    public function replacementInput(ReplacementData $replacement): array
    {
        return [
            'supplier_id' => $replacement->values['supplier_id'] ?? null,
            'paid_on' => $replacement->values['paid_on'] ?? $replacement->date->toDateString(),
            'amount' => Money::ofMinor($replacement->totalMinor)->toTaka(),
        ];
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SupplierPayment $document */
        $document->loadMissing(['moneyAccount:id,name', 'supplier:id,name', 'allocations.bill:id,bill_number']);
        $money = $document->moneyAccount?->name ?? 'the money account';
        $net = $document->netPaid()->minor;
        $bills = $document->allocations->map(fn ($a) => $a->bill?->bill_number)->filter()->unique()->implode(', ');
        $on = Tk::date($dates->reversalDate);

        $lines = [
            new EffectLine(Tk::of($net)." goes back into {$money} on {$on}.", $money, $net, 'ledger'),
        ];

        if ($bills !== '') {
            $lines[] = new EffectLine("Bill {$bills} is owed again by what this payment settled.", null, null, 'allocation');
        }

        if ($replacement === null) {
            return $lines;
        }

        $values = $replacement->values;
        $newMoney = MoneyAccount::query()->find($values['money_account_id'])?->name ?? 'the money account';
        $newBills = SupplierBill::query()->whereIn('id', array_column($values['allocations'], 'supplier_bill_id'))->pluck('bill_number')->implode(', ');
        $lines[] = new EffectLine(Tk::of($replacement->totalMinor)." is paid from {$newMoney} on ".Tk::date($replacement->date).", settling bill {$newBills}.", $newMoney, -$replacement->totalMinor, 'ledger');

        if (($values['approval_reasons'] ?? []) !== []) {
            $lines[] = new EffectLine('The second person must be a purchasing approver: the corrected payment needs one ('.implode('; ', $values['approval_reasons']).').');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = "Payment #{$document->getKey()}";

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The corrected payment is a new record, labelled \"Correction of {$number}\"."]
            : ["{$number} stays, marked Reversed, with the bills it had settled.", 'The reversal gets its own JV number.'];
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
        /** @var SupplierPayment $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SupplierPayment $document */
        try {
            $reversed = $this->reversePayment->handle($document, $ctx->actor, $ctx->reason->display(), true, $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $reversed->reversal_journal_id === null ? null : Journal::query()->find($reversed->reversal_journal_id);
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var SupplierPayment $document */
        $values = $replacement->values;

        $record = fn () => $this->recordPayment->handle(
            supplier: $document->supplier()->firstOrFail(),
            moneyAccount: MoneyAccount::query()->findOrFail($values['money_account_id']),
            paidOn: $values['paid_on'],
            allocations: array_map(fn (array $row) => [
                'bill' => SupplierBill::query()->findOrFail($row['supplier_bill_id']),
                'amount' => $row['amount'],
            ], $values['allocations']),
            createdBy: $ctx->actor,
            reference: $values['reference'],
            note: $values['note'],
            tdsRate: $values['tds_rate_id'] === null ? null : WithholdingRate::query()->find($values['tds_rate_id']),
            vdsRate: $values['vds_rate_id'] === null ? null : WithholdingRate::query()->find($values['vds_rate_id']),
            excessToAdvance: $values['excess_to_advance'],
            pass: $ctx->pass,
        );

        try {
            return $this->underPurchasingApproval($record, $ctx->actor, $ctx->approver, 'payment');
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['paid_on' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var SupplierPayment $successor */
        return $this->posting->existingJournal($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseSupplierPayment wrote the Reversible columns with the reason.
    }

    public function successorClass(): string
    {
        return SupplierPayment::class;
    }

    /**
     * This type's own invariant: the original is marked and settles nothing;
     * every payment's amount is what its journal debits to Accounts Payable;
     * the corrected payment's allocations add up to its amount.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SupplierPayment $original */
        if (! $original->isReversed()) {
            throw new LogicException("Payment #{$original->id} was not marked.");
        }

        if (SupplierPaymentAllocation::query()->where('supplier_payment_id', $original->id)->exists()) {
            throw new LogicException("Payment #{$original->id} still settles a bill.");
        }

        if ($successor === null) {
            return;
        }

        /** @var SupplierPayment $successor */
        $amount = Money::ofTaka((string) $successor->amount)->minor;
        $allocated = Money::ofTaka((string) $successor->allocatedTotal())->minor;
        $journal = $this->posting->existingJournal($successor);

        if ($allocated !== $amount) {
            throw new LogicException("Payment #{$successor->id}'s allocations do not add up to it.");
        }

        if ($journal !== null) {
            $payable = AccountMapping::resolve('purchasing.accounts_payable', Carbon::parse($journal->journal_date->toDateString()))->id;

            if ((int) $journal->lines()->where('account_id', $payable)->sum('amount_minor') !== $amount) {
                throw new LogicException("Payment #{$successor->id}'s amount does not match what its journal settles.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** @param array<string, mixed> $after */
    private function sameAs(SupplierPayment $payment, array $after): bool
    {
        $before = [
            $payment->paid_on?->toDateString(), (int) $payment->money_account_id, $payment->reference ?: null, $payment->note ?: null,
            $payment->tds_rate_id === null ? null : (int) $payment->tds_rate_id, $payment->vds_rate_id === null ? null : (int) $payment->vds_rate_id,
            $payment->allocations->map(fn ($a) => [(int) $a->supplier_bill_id, Money::ofTaka((string) $a->amount)->minor])->sort()->values()->all(),
        ];

        $now = [
            $after['paid_on'], (int) $after['money_account_id'], $after['reference'], $after['note'], $after['tds_rate_id'], $after['vds_rate_id'],
            collect($after['allocations'])->map(fn ($a) => [(int) $a['supplier_bill_id'], Money::ofTaka((string) $a['amount'])->minor])->sort()->values()->all(),
        ];

        return $before === $now && ! $after['excess_to_advance'];
    }
}
