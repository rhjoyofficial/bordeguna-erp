<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\IncomeVoucherRules;
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
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Other income's own reversal and successor (PHASE-0-CONTRACT v2, 3.3).
 *
 *   reverse()          ReverseJournal on the voucher's one journal, on the date
 *                      the date policy gave (its own date by default)
 *   createSuccessor()  RecordIncomeVoucher with the edited form: every rule the
 *                      screen has (income heads only, no sales heads, an active
 *                      mapped money account, not in the future) applies again
 *   markOriginal()     the Reversible columns; money fields are never touched
 *
 * ⚠ Evidence stays on the original (risk R6): a copied attachment row pointing
 * at the same stored file could delete a file the original still needs.
 */
class IncomeVoucherCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly IncomeVoucherActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RecordIncomeVoucher $recordIncomeVoucher,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return IncomeVoucher::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $voucher = IncomeVoucher::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        // Its journals too, so a reversal by another screen waits for us.
        Journal::query()
            ->where('source_type', $voucher->getMorphClass())
            ->where('source_id', $voucher->getKey())
            ->lockForUpdate()
            ->get(['id']);

        return $voucher->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var IncomeVoucher $document */
        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $journalIds = array_map(fn ($pair) => $pair[0], $journals);

        $matched = $journalIds === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journalIds)->select('id'))
            ->count();

        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->voucher_number, $document->received_on?->toDateString(),
                $document->money_account_id, $document->customer_id, $document->received_from,
                (string) $document->total, $document->reference, $document->note,
                $document->reversed_at?->toIso8601String(), $document->journal_id,
            ],
            'lines' => $document->lines()->orderBy('id')->get(['account_id', 'amount', 'description'])
                ->map(fn ($l) => [(int) $l->account_id, (string) $l->amount, $l->description])->all(),
            'journals' => $journals,
            'matched' => $matched,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var IncomeVoucher $document */
        $data = IncomeVoucherRules::validate($input);
        $totalMinor = IncomeVoucherRules::totalMinor($data);

        if ($totalMinor <= 0) {
            throw ValidationException::withMessages(['lines' => 'A voucher needs at least one line with an amount.']);
        }

        if ($this->sameAs($document, $data)) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        return new ReplacementData(Carbon::parse($data['received_on'])->startOfDay(), $totalMinor, $data, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var IncomeVoucher $document */
        $document->loadMissing(['moneyAccount', 'lines.account']);
        $money = $document->moneyAccount?->name ?? 'the money account';
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);

        if ($action === DocumentAction::Reverse) {
            $lines = [new EffectLine(Tk::of($total)." comes out of {$money} on {$on}.", $money, -$total, 'ledger')];

            foreach ($document->lines as $line) {
                $amount = Money::ofTaka((string) $line->amount)->minor;
                $lines[] = new EffectLine(Tk::of($amount).' comes out of '.($line->account?->name ?? 'the income head').'.', $line->account?->name, -$amount, 'ledger');
            }

            return $lines;
        }

        $heads = $document->lines->map(fn ($l) => $l->account?->name)->filter()->unique()->implode(', ');
        $newMoney = MoneyAccount::query()->find($replacement->values['money_account_id'])?->name ?? 'the money account';
        $newHeads = Account::query()->whereIn('id', array_column($replacement->values['lines'], 'account_id'))->pluck('name')->unique()->implode(', ');
        $newOn = Tk::date($replacement->date);

        $lines = [
            new EffectLine(Tk::of($total)." out of {$money} and {$heads} on {$on}.", $money, -$total, 'ledger'),
            new EffectLine(Tk::of($replacement->totalMinor)." into {$newMoney} and {$newHeads} on {$newOn}.", $newMoney, $replacement->totalMinor, 'ledger'),
        ];

        if ((int) $replacement->values['money_account_id'] === (int) $document->money_account_id && $replacement->totalMinor !== $total) {
            $difference = $replacement->totalMinor - $total;
            $from = Tk::date($dates->reversalDate->max($replacement->date));
            $lines[] = new EffectLine("{$money}'s balance from {$from} onward goes ".($difference < 0 ? 'down' : 'up').' by '.Tk::of(abs($difference)).'.', $money, $difference, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $document->voucher_number;

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Corrected.", "The new voucher gets a new number and the label \"Correction of {$number}\".", "The evidence stays on {$number}."]
            : ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->received_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return Money::ofTaka((string) $document->total)->minor;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_filter([(int) $document->created_by, $lastEditor === null ? null : (int) $lastEditor]));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var IncomeVoucher $document */
        if ($document->journal_id !== null) {
            return Journal::query()->find($document->journal_id);
        }

        // An older voucher the backfill could not settle: only when there is
        // exactly one live journal, never a guess (data check D1).
        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->get();

        return $live->count() === 1 ? $live->first() : null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->voucher_number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Reversal of {$document->voucher_number}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        $args = IncomeVoucherRules::arguments($replacement->values);

        try {
            return $this->recordIncomeVoucher->handle(
                moneyAccount: $args['moneyAccount'],
                receivedOn: $args['receivedOn'],
                lines: $args['lines'],
                createdBy: $ctx->actor,
                customer: $args['customer'],
                receivedFrom: $args['receivedFrom'],
                reference: $args['reference'],
                note: $args['note'],
                pass: $ctx->pass,
                correctionOf: $ctx->originalNumber,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return $successor->journal_id === null ? null : Journal::query()->find($successor->journal_id);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return IncomeVoucher::class;
    }

    /**
     * v2 (C11), this type's own invariant: the voucher's total is exactly the
     * debit of its one journal (the money line), for the original and for the
     * successor, and the original is marked.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var IncomeVoucher $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->voucher_number} was not marked.");
        }

        foreach (array_filter([$original, $successor]) as $voucher) {
            $journal = $voucher->journal_id !== null
                ? Journal::query()->find($voucher->journal_id)
                : Journal::query()->where('source_type', $voucher->getMorphClass())->where('source_id', $voucher->getKey())->whereNull('reverses_journal_id')->orderBy('id')->first();

            if ($journal === null) {
                throw new LogicException("{$voucher->voucher_number} has no journal.");
            }

            if ($journal->totalDebits()->minor !== $this->totalMinor($voucher)) {
                throw new LogicException("{$voucher->voucher_number}'s total does not match its journal.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }

    private function sameAs(IncomeVoucher $voucher, array $data): bool
    {
        $voucher->loadMissing('lines');
        $norm = fn ($v) => $v === '' ? null : $v;

        $before = [
            (int) $voucher->money_account_id, $voucher->received_on->toDateString(), $voucher->customer_id === null ? null : (int) $voucher->customer_id,
            $norm($voucher->received_from), $norm($voucher->reference), $norm($voucher->note),
            $voucher->lines->map(fn ($l) => [(int) $l->account_id, Money::ofTaka((string) $l->amount)->minor, $norm($l->description)])->values()->all(),
        ];

        $after = [
            (int) $data['money_account_id'], Carbon::parse($data['received_on'])->toDateString(), empty($data['customer_id']) ? null : (int) $data['customer_id'],
            $norm($data['received_from'] ?? null), $norm($data['reference'] ?? null), $norm($data['note'] ?? null),
            array_values(array_map(fn ($l) => [(int) $l['account_id'], Money::ofTaka((string) $l['amount'])->minor, $norm($l['description'] ?? null)], $data['lines'])),
        ];

        return $before === $after;
    }
}
