<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\MoneyTransfer;
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
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): a money transfer's own reversal and
 * successor.
 *
 *   reverse()          ReverseJournal on the transfer's one journal, on the
 *                      date the date policy gave; both accounts and the fee
 *                      move back together, because they are one journal
 *   createSuccessor()  RecordMoneyTransfer with the edited form: every rule of
 *                      the record screen (same account both sides, an active
 *                      mapped account, a fee head, money available) applies
 *   markOriginal()     the Reversible columns
 */
class MoneyTransferCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly MoneyTransferActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RecordMoneyTransfer $record,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return MoneyTransfer::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $transfer = MoneyTransfer::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($transfer);

        return $transfer;
    }

    public function fingerprint(Model $document): string
    {
        /** @var MoneyTransfer $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->transfer_number, $document->transferred_on?->toDateString(),
                (int) $document->from_money_account_id, (int) $document->to_money_account_id,
                (string) $document->amount, (string) $document->fee, $document->fee_account_id,
                $document->reference, $document->note, $document->reversed_at?->toIso8601String(),
            ],
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var MoneyTransfer $document */
        $data = Validator::make($input, [
            'transferred_on' => ['required', 'date_format:Y-m-d'],
            'from_money_account_id' => ['required', 'integer'],
            'to_money_account_id' => ['required', 'integer', 'different:from_money_account_id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'fee_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'to_money_account_id.different' => 'Money cannot be transferred to the account it came from.',
        ])->validate();

        foreach (['from_money_account_id', 'to_money_account_id'] as $field) {
            if (! MoneyAccount::query()->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose one of this business\'s own accounts.']);
            }
        }

        $fee = Money::ofTaka((string) ($data['fee'] ?? '0'));

        if ($fee->minor > 0 && (empty($data['fee_account_id']) || ! Account::query()->whereKey($data['fee_account_id'])->exists())) {
            throw ValidationException::withMessages(['fee_account_id' => 'A transfer fee needs an expense account to charge it to.']);
        }

        $values = [
            'transferred_on' => $data['transferred_on'],
            'from_money_account_id' => (int) $data['from_money_account_id'],
            'to_money_account_id' => (int) $data['to_money_account_id'],
            'amount' => Money::ofTaka((string) $data['amount'])->toTaka(),
            'fee' => $fee->toTaka(),
            'fee_account_id' => $fee->minor > 0 ? (int) $data['fee_account_id'] : null,
            'reference' => blank($data['reference'] ?? null) ? null : trim((string) $data['reference']),
            'note' => blank($data['note'] ?? null) ? null : trim((string) $data['note']),
        ];

        $before = [
            'transferred_on' => $document->transferred_on->toDateString(),
            'from_money_account_id' => (int) $document->from_money_account_id,
            'to_money_account_id' => (int) $document->to_money_account_id,
            'amount' => Money::ofTaka((string) $document->amount)->toTaka(),
            'fee' => Money::ofTaka((string) $document->fee)->toTaka(),
            'fee_account_id' => $document->fee_account_id === null ? null : (int) $document->fee_account_id,
            'reference' => blank($document->reference) ? null : $document->reference,
            'note' => blank($document->note) ? null : $document->note,
        ];

        if ($values === $before) {
            throw ValidationException::withMessages(['amount' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        $total = Money::ofTaka($values['amount'])->minor + $fee->minor;

        return new ReplacementData(Carbon::parse($values['transferred_on'])->startOfDay(), $total, $values, $values);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var MoneyTransfer $document */
        $document->loadMissing(['from', 'to']);
        $on = Tk::date($dates->reversalDate);
        $withdrawn = Money::ofTaka($document->totalWithdrawn())->minor;
        $arrived = Money::ofTaka((string) $document->amount)->minor;
        $from = $document->from?->name ?? 'the source account';
        $to = $document->to?->name ?? 'the destination account';

        $lines = [
            new EffectLine(Tk::of($withdrawn)." goes back into {$from} on {$on}.", $from, $withdrawn, 'ledger'),
            new EffectLine(Tk::of($arrived)." comes out of {$to} on {$on}.", $to, -$arrived, 'ledger'),
        ];

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $amount = Money::ofTaka($replacement->values['amount'])->minor;
            $newFrom = MoneyAccount::query()->find($replacement->values['from_money_account_id'])?->name ?? 'the source account';
            $newTo = MoneyAccount::query()->find($replacement->values['to_money_account_id'])?->name ?? 'the destination account';
            $lines[] = new EffectLine(Tk::of($amount)." moves from {$newFrom} to {$newTo} on ".Tk::date($replacement->date).'.', $newTo, $amount, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $document->transfer_number;

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The new transfer gets a new number and the label \"Correction of {$number}\"."]
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->transferred_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var MoneyTransfer $document */
        return Money::ofTaka($document->totalWithdrawn())->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->transfer_number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of transfer {$document->transfer_number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        $v = $replacement->values;

        try {
            return $this->record->handle(
                from: MoneyAccount::query()->findOrFail($v['from_money_account_id']),
                to: MoneyAccount::query()->findOrFail($v['to_money_account_id']),
                amount: $v['amount'],
                transferredOn: $v['transferred_on'],
                createdBy: $ctx->actor,
                fee: $v['fee'],
                feeAccount: $v['fee_account_id'] === null ? null : Account::query()->findOrFail($v['fee_account_id']),
                reference: $v['reference'],
                note: $v['note'],
                pass: $ctx->pass,
                correctionOf: $ctx->originalNumber,
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['transferred_on' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return $this->liveJournalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->markReversed($document, $reversal, $ctx);
    }

    public function successorClass(): string
    {
        return MoneyTransfer::class;
    }

    /** Marked, its journal reversed, and the successor's journal is exactly what left the source. */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        $this->assertReversedBy($original, (string) $original->transfer_number);

        if ($successor !== null) {
            /** @var MoneyTransfer $successor */
            $journal = $this->liveJournalOf($successor) ?? throw new LogicException("{$successor->transfer_number} has no journal.");

            if ($journal->totalDebits()->minor !== Money::ofTaka($successor->totalWithdrawn())->minor) {
                throw new LogicException("{$successor->transfer_number}'s total does not match its journal.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }
}
