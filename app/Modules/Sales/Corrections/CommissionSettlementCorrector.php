<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Support\PayrollInputWindow;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A commission settlement reversed and KEPT (25 Oct 2026).
 *
 *   reverse()       owed or paid now: ReverseJournal on its one journal, on the
 *                   date the date policy gave. Next payroll: the addition is
 *                   cancelled (re-checked under the lock: the month must still
 *                   take inputs); no journal, because none was posted
 *   markOriginal()  the Reversible columns
 *
 * The money it took goes back to "to settle"; the next settlement of the
 * agreement takes it again (or less, if sales were returned meanwhile).
 */
class CommissionSettlementCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly CommissionSettlementActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CommissionSettlement::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $settlement = CommissionSettlement::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($settlement);

        if ($settlement->payroll_adjustment_id !== null) {
            PayrollAdjustment::query()->withoutGlobalScopes()->whereKey($settlement->payroll_adjustment_id)->lockForUpdate()->first();
        }

        return $settlement;
    }

    public function fingerprint(Model $document): string
    {
        /** @var CommissionSettlement $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->settled_on?->toDateString(), $document->through_date?->toDateString(), (string) $document->amount,
                $document->method, $document->status, $document->reversed_at?->toIso8601String(), $document->payroll_adjustment_id,
            ],
            'payments' => $document->payments()->orderBy('id')->get(['id', 'reversed_at'])->map(fn ($p) => [$p->id, $p->reversed_at?->toIso8601String()])->all(),
            'payroll' => $document->payrollAdjustment?->cancelled_at?->toIso8601String(),
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A commission settlement is reversed and settled again. It is not corrected in one step.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CommissionSettlement $document */
        $document->loadMissing(['agreement', 'payrollAdjustment.period']);
        $amount = $document->money()->minor;
        $who = $document->agreement?->partyName();

        if ($document->method === 'payroll') {
            return [
                new EffectLine('The commission of '.Tk::of($amount)." for {$who} comes off the {$document->payrollAdjustment?->period?->label()} payroll.", null, -$amount, 'payroll'),
                new EffectLine('It goes back to "to settle" on '.$document->agreement?->number.'.', null, null, 'document'),
            ];
        }

        return [
            new EffectLine('The commission of '.Tk::of($amount)." for {$who} is undone on ".Tk::date($dates->reversalDate).'.', 'Sales Commission', -$amount, 'ledger'),
            new EffectLine('It goes back to "to settle" on '.$document->agreement?->number.'.', null, null, 'document'),
        ];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return ["{$number} stays, marked Reversed.", $document->getAttribute('method') === 'payroll' ? 'The payroll addition stays, marked cancelled.' : 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->settled_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var CommissionSettlement $document */
        return $document->money()->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CommissionSettlement $document */
        $number = $this->policy->numberOf($document);

        if ($document->method === 'payroll') {
            $adjustment = $document->payrollAdjustment()->with('period')->first();

            if ($adjustment !== null && $adjustment->cancelled_at === null) {
                if (! PayrollInputWindow::accepts($adjustment->period)) {
                    throw ValidationException::withMessages(['reason_text' => "{$number} is already on the {$adjustment->period?->label()} payroll, which no longer takes changes."]);
                }

                $adjustment->forceFill(['cancelled_at' => now(), 'cancelled_by' => $ctx->actor->id, 'cancel_reason' => "Commission {$number} reversed: {$ctx->reason->display()}"])->save();
            }

            return null;
        }

        $journal = $this->journalOf($document) ?? throw new LogicException("{$number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of commission {$number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A commission settlement has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->markReversed($document, $reversal, $ctx);
    }

    public function successorClass(): string
    {
        return CommissionSettlement::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CommissionSettlement $original */
        $number = $this->policy->numberOf($original);

        if ($original->method === 'payroll') {
            if ($original->reversed_at === null) {
                throw new LogicException("{$number} was not marked.");
            }

            if ($original->payrollAdjustment !== null && $original->payrollAdjustment->cancelled_at === null) {
                throw new LogicException("{$number}'s payroll addition is still standing.");
            }

            return;
        }

        $this->assertReversedBy($original, $number);
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can(CommissionSettlementActionPolicy::PERMISSION);
    }
}
