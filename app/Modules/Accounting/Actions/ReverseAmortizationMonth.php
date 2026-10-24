<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Accrual or
 * prepayment, a released month: Reverse this month. Month open, or first open
 * day. Owner"). Before this, a released month could not be undone at all.
 *
 *   which month   the LATEST released one only, so the schedule's pointer
 *                 steps back one month at a time and never leaves a hole;
 *                 earlier months are reversed one after another the same way
 *   the date      the month's own date while it is open, otherwise the first
 *                 open day (the plan's rule; no pass is involved)
 *   the schedule  stopped (cancelled): a month is reversed because the plan
 *                 was wrong, and an active schedule would simply release the
 *                 same month again tonight. A corrected schedule is set up anew.
 *
 * ⚠ All in one transaction, the schedule re-read under a lock: a double click
 * or the nightly run at the same moment finds the pointer moved and stops.
 */
class ReverseAmortizationMonth
{
    public function __construct(private readonly ReverseJournal $reverseJournal) {}

    /** @throws ValidationException */
    public function handle(AmortizationSchedule $schedule, ActionReason $reason, User $by, ?string $seenThrough): Journal
    {
        return DB::transaction(function () use ($schedule, $reason, $by, $seenThrough) {
            $schedule = AmortizationSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if ($schedule->released_through === null) {
                throw ValidationException::withMessages(['reason_text' => 'No month of this schedule has been released yet.']);
            }

            if ($seenThrough !== null && $schedule->released_through->toDateString() !== $seenThrough) {
                throw ValidationException::withMessages(['stale' => 'This record changed after you opened it. Please look again.']);
            }

            $month = $schedule->released_through->copy()->startOfMonth();

            $journal = Journal::query()
                ->where('source_type', $schedule->getMorphClass())
                ->where('source_id', $schedule->getKey())
                ->whereDate('journal_date', $month->toDateString())
                ->whereNull('reverses_journal_id')
                ->whereDoesntHave('reversal')
                ->lockForUpdate()
                ->get();

            if ($journal->count() !== 1) {
                throw ValidationException::withMessages(['reason_text' => 'The ledger entry of '.$month->format('F Y').' could not be found exactly once. Ask the administrator to check it.']);
            }

            try {
                $on = AccountingPeriod::firstOpenOnOrAfter($month);
                $reversal = $this->reverseJournal->handle($journal->first()->load('lines'), $by, $on, "Reversal of {$schedule->name}, ".$month->format('F Y').": {$reason->display()}");
            } catch (PeriodClosedException|JournalException $e) {
                throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
            }

            $previous = $month->copy()->subMonth();
            $wasStatus = $schedule->status;

            $schedule->update([
                'released_through' => $previous->lt($schedule->start_month->copy()->startOfMonth()) ? null : $previous->toDateString(),
                'status' => 'cancelled',
            ]);

            CorrectionLog::event('reversed', $by, $schedule, [
                'number' => $schedule->name,
                'month' => $month->format('F Y'),
                'reversal_date' => Carbon::parse($reversal->journal_date)->toDateString(),
                'reversal_journal' => $reversal->journal_number,
                'schedule_was' => $wasStatus,
                'reason' => $reason->display(),
            ]);

            return $reversal;
        });
    }
}
