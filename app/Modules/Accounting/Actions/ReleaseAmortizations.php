<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Releases every amortization month that has fallen due (Module 2, Slice 4).
 *
 * One journal per schedule per month, posted through PostJournal so period
 * locks and every ledger rule apply. The journal is dated the FIRST of the
 * month it releases: the cost belongs to the month, not to whenever the
 * scheduler happened to run.
 *
 * ⚠ Idempotent by the released_through pointer, advanced inside the same
 * transaction as each posting - a crash between months resumes exactly
 * where it stopped, and running twice releases nothing twice.
 */
class ReleaseAmortizations
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * Release everything due through $date, for the CURRENT business context.
     * Returns how many monthly releases were posted.
     *
     * ⚠ Each schedule posts AS ITS OWN CREATOR - the person who set the plan
     * is the person the ledger names. $fallback signs only where the creator
     * is gone; with neither, the schedule is skipped rather than posted as
     * somebody it is not.
     */
    public function through(string|Carbon $date, ?User $fallback = null): int
    {
        // ⚠ Never past the month we are standing in: the ledger (rightly)
        // refuses future-dated journals, and a cost belongs to a month only
        // once that month exists. Asking for further ahead just waits.
        $through = Carbon::parse($date)->startOfMonth()->min(Carbon::now()->startOfMonth());
        $posted = 0;

        $schedules = AmortizationSchedule::query()->where('status', 'active')->get();

        foreach ($schedules as $schedule) {
            $actor = ($schedule->created_by ? User::find($schedule->created_by) : null) ?? $fallback;

            if ($actor === null) {
                continue;
            }

            while (($index = $schedule->nextIndex()) !== null) {
                $month = $schedule->monthAt($index);

                if ($month->greaterThan($through)) {
                    break;
                }

                $amount = Money::ofTaka(number_format($schedule->amountForIndex($index), 2, '.', ''));

                [$debitAccount, $creditAccount] = match ($schedule->kind) {
                    // The cost was paid in advance; each month consumes it.
                    'prepaid_expense' => [$schedule->target_account_id, $schedule->source_account_id],
                    // The cost is recognised before its bill; the liability grows.
                    'accrued_expense' => [$schedule->target_account_id, $schedule->source_account_id],
                    // The money arrived first; each month earns it.
                    'deferred_income' => [$schedule->source_account_id, $schedule->target_account_id],
                };

                DB::transaction(function () use ($schedule, $month, $amount, $debitAccount, $creditAccount, $actor, $index) {
                    $this->postJournal->handle(new JournalDraft(
                        date: $month,
                        lines: [
                            JournalLineDraft::debit($debitAccount, $amount),
                            JournalLineDraft::credit($creditAccount, $amount),
                        ],
                        memo: sprintf('%s - month %d of %d (%s)',
                            $schedule->name, $index + 1, $schedule->months, $month->format('M Y')),
                        source: $schedule,
                    ), $actor);

                    $done = $index === $schedule->months - 1;

                    $schedule->update([
                        'released_through' => $month->toDateString(),
                        'status' => $done ? 'done' : 'active',
                    ]);
                });

                $posted++;
                $schedule->refresh();
            }
        }

        return $posted;
    }
}
