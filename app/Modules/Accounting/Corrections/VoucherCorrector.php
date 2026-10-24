<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyHeads;
use App\Modules\Accounting\Support\VoucherForm;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): a posted voucher's own reversal and
 * corrected voucher.
 *
 *   reverse()          ReverseVoucher, on the date the date policy gave and
 *                      with the Owner's pass for a closed month: the journal is
 *                      mirrored, and every receipt, payment, refund and
 *                      adjustment it made is marked reversed with it
 *   createSuccessor()  SaveVoucherDraft + PostVoucher with the edited form, as
 *                      "Save and post" would, copied from the original: every
 *                      rule the voucher screens have applies again
 *   markOriginal()     nothing more: ReverseVoucher already marked it Reversed
 *                      (status, journal, reason, who); the correction link
 *                      says Corrected and names the new voucher
 *
 * ⚠ A Correct never names a customer or supplier head, a loan or a bill-wise
 * split: those create documents whose links would change (FINAL-PLAN 4.2,
 * "Then: Reverse and copy"). A plain expense, income, contra or journal is
 * what Correct is for.
 */
class VoucherCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly VoucherActionPolicy $policy,
        private readonly ReverseVoucher $reverseVoucher,
        private readonly SaveVoucherDraft $saveDraft,
        private readonly PostVoucher $postVoucher,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return Voucher::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $voucher = Voucher::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($voucher);

        return $voucher->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var Voucher $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->status->value, $document->voucher_number, $document->voucher_date?->toDateString(),
                $document->money_account_id, $document->to_money_account_id, $document->party_type, $document->party_id,
                (int) $document->total_minor, $document->instrument_type, $document->instrument_number, $document->journal_id,
                $document->reversal_journal_id,
            ],
            'lines' => $document->lines()->orderBy('id')->get(['account_id', 'side', 'amount_minor', 'party_type', 'party_id', 'loan_contract_id'])
                ->map(fn ($l) => [(int) $l->account_id, $l->side, (int) $l->amount_minor, $l->party_type, $l->party_id, $l->loan_contract_id])->all(),
            'cheque' => ChequeStatus::query()->current()->where('voucher_id', $document->id)->value('status'),
            'settles' => VoucherSettlementCheck::settlesSomething($document),
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var Voucher $document */
        $data = Validator::make($input, VoucherForm::rules())->validate();
        $type = $document->type;

        if (is_string($data['party'] ?? null) && (str_starts_with($data['party'], 'supplier:') || str_starts_with($data['party'], 'customer:'))) {
            throw ValidationException::withMessages(['party' => 'A correction cannot pay or receive against a supplier or customer. Reverse it, then Copy.']);
        }

        $rows = array_values(array_filter($data['lines'] ?? [], fn ($line) => is_numeric($line['amount'] ?? null) && (float) $line['amount'] > 0));
        $heads = Account::query()->findMany(array_column($rows, 'account_id'))->keyBy('id');

        foreach ($rows as $index => $line) {
            $head = $heads->get((int) ($line['account_id'] ?? 0));
            $person = $head === null || $head->is_postable ? null : PartyHeads::entityFor($head);

            if ($person instanceof Supplier || $person instanceof Customer) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'A correction cannot name a supplier or customer ledger. Reverse it, then Copy.']);
            }

            if (! empty($line['loan_contract_id']) || ! empty($line['bill_wise']) || ! empty($line['tds_rate_id']) || ! empty($line['vds_rate_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'A correction cannot move a loan, a bill or tax held back. Reverse it, then Copy.']);
            }
        }

        if ($type->hasLines() && $rows === []) {
            throw ValidationException::withMessages(['lines' => 'A voucher needs at least one line with an amount.']);
        }

        $total = VoucherForm::totalMinor($type, $data);

        if ($total <= 0) {
            throw ValidationException::withMessages([$type->hasLines() ? 'lines' : 'amount' => 'Enter the amount.']);
        }

        foreach (['money_account_id', 'to_money_account_id'] as $field) {
            if (! empty($data[$field]) && ! MoneyAccount::query()->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose one of this business\'s own accounts.']);
            }
        }

        $values = $this->normalised($type, $data);

        if ($values === $this->normalised($type, VoucherForm::of($document))) {
            throw ValidationException::withMessages(['voucher' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        // The type rides along: the duplicate warning compares like with like.
        $values['type'] = $type->value;

        return new ReplacementData(Carbon::parse($data['voucher_date'])->startOfDay(), $total, $values, $values);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var Voucher $document */
        $document->loadMissing(['journal.lines.account', 'moneyAccount', 'toMoneyAccount']);
        $on = Tk::date($dates->reversalDate);
        $total = (int) $document->total_minor;
        $lines = [];

        foreach ($document->journal?->lines ?? [] as $line) {
            $amount = (int) $line->amount_minor;
            $name = $line->account?->name ?? 'a ledger head';
            $lines[] = new EffectLine(($amount > 0 ? 'Credit ' : 'Debit ').Tk::of(abs($amount))." to {$name} on {$on}, undoing {$this->policy->numberOf($document)}.", $name, -$amount, 'ledger');
        }

        $receipts = CustomerReceipt::query()->where('voucher_id', $document->id)->whereNull('reversed_at')->count();

        if ($receipts > 0 || VoucherSettlementCheck::settlesSomething($document)) {
            $lines[] = new EffectLine('The receipts, payments, refunds or adjustment it made are reversed with it, and the invoices and bills they settled owe again.', null, null, 'allocation');
        }

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $money = MoneyAccount::query()->find($replacement->values['money_account_id'] ?? null)?->name;
            $lines[] = new EffectLine('A new '.strtolower($document->type->label()).' of '.Tk::of($replacement->totalMinor).($money ? " through {$money}" : '').' posts on '.Tk::date($replacement->date).'.', $money, $replacement->totalMinor, 'ledger');

            if ((int) ($replacement->values['money_account_id'] ?? 0) === (int) $document->money_account_id && $replacement->totalMinor !== $total && $money !== null) {
                $difference = $replacement->totalMinor - $total;
                $lines[] = new EffectLine("{$money} moves by ".Tk::of(abs($difference)).' less or more than before, from '.Tk::date($dates->reversalDate->max($replacement->date)).' onward.', $money, $difference, 'ledger');
            }
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The new voucher gets a new number and the label \"Correction of {$number}\".", "The proof attached stays on {$number}."]
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->voucher_date->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return (int) $document->total_minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var Voucher $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        try {
            $reversed = $this->reverseVoucher->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|JournalException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $reversed->reversal_journal_id === null ? null : Journal::query()->find($reversed->reversal_journal_id);
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var Voucher $document */
        $values = $replacement->values;
        unset($values['type']);

        try {
            $draft = $this->saveDraft->handle($document->type, $values + ['copied_from_id' => $document->id], $ctx->actor);

            return $this->postVoucher->handle($draft, $ctx->actor, $ctx->pass);
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['voucher_date' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return $this->journalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseVoucher marked it (status, reversal journal, reason, who);
        // nothing else on a posted voucher is written.
    }

    public function successorClass(): string
    {
        return Voucher::class;
    }

    /**
     * Marked Reversed with its reversal, and the corrected voucher is posted
     * with a journal that carries exactly its total.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var Voucher $original */
        $number = $this->policy->numberOf($original);

        if ($original->status !== VoucherStatus::Reversed || $original->reversal_journal_id === null) {
            throw new LogicException("{$number} was not marked.");
        }

        if ($successor !== null) {
            /** @var Voucher $successor */
            $journal = $this->journalOf($successor) ?? throw new LogicException("{$successor->title()} has no journal.");

            if ($successor->status !== VoucherStatus::Posted || $journal->totalDebits()->minor !== (int) $successor->total_minor) {
                throw new LogicException("{$successor->title()}'s total does not match its journal.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }

    /** The fields a correction compares, the way the save would store them. */
    private function normalised(VoucherType $type, array $data): array
    {
        $text = fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v);

        return [
            'voucher_date' => Carbon::parse($data['voucher_date'])->toDateString(),
            'money_account_id' => $type->usesMoneyAccount() && ! empty($data['money_account_id']) ? (int) $data['money_account_id'] : null,
            'to_money_account_id' => $type === VoucherType::Contra && ! empty($data['to_money_account_id']) ? (int) $data['to_money_account_id'] : null,
            'counterparty' => $text($data['counterparty'] ?? null),
            'party' => $text($data['party'] ?? null),
            'narration' => $text($data['narration'] ?? null),
            'instrument_type' => $type->usesMoneyAccount() ? $text($data['instrument_type'] ?? null) : null,
            'instrument_number' => $type->usesMoneyAccount() ? $text($data['instrument_number'] ?? null) : null,
            'instrument_date' => $type->usesMoneyAccount() && $text($data['instrument_date'] ?? null) !== null ? Carbon::parse($data['instrument_date'])->toDateString() : null,
            'instrument_bank' => $type->usesMoneyAccount() ? $text($data['instrument_bank'] ?? null) : null,
            'amount' => $type->hasLines() ? null : Money::ofTaka((string) ($data['amount'] ?? '0'))->toTaka(),
            'lines' => $type->hasLines() ? array_values(array_map(fn ($line) => [
                'account_id' => (int) ($line['account_id'] ?? 0),
                'side' => $type->fixedLineSide() ?? ($line['side'] ?? 'debit'),
                'amount' => Money::ofTaka((string) $line['amount'])->toTaka(),
                'narration' => $text($line['narration'] ?? null),
                'party_id' => empty($line['party_id']) ? null : (int) $line['party_id'],
            ], array_filter($data['lines'] ?? [], fn ($line) => is_numeric($line['amount'] ?? null) && (float) $line['amount'] > 0))) : [],
        ];
    }
}
