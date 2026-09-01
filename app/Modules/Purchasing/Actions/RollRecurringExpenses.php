<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Carbon\CarbonImmutable;

/**
 * Generates the next few periods of every active standing cost.
 *
 * ⛔⛔ IT CREATES NOTHING BUT A REMINDER. No voucher, no journal, no money. An
 * expense voucher in this ERP is paid the moment it exists, so a job that made
 * one on a schedule would record a payment nobody had made - and would keep
 * doing it, every month, in a system whose whole point is to be believed.
 *
 * ⚠⚠ IDEMPOTENT BY CONSTRUCTION, not by remembering to check. Every occurrence
 * is keyed `recurring:{id}:{YYYY-MM}` behind a unique index, so running this
 * twice, or restoring last night's backup and running it again, cannot double
 * anything. That is the same guarantee the leave ledger and payroll adjustments
 * are built on, and it is what makes a nightly schedule safe to leave alone.
 */
class RollRecurringExpenses
{
    /**
     * How far ahead to look. Far enough that a monthly cost is always visible
     * before it is due; near enough that a schedule changed in March does not
     * leave a year of stale predictions behind it.
     */
    public const HORIZON = 3;

    /**
     * @return int how many occurrences were created
     */
    public function handle(?CarbonImmutable $today = null): int
    {
        $today = $today ?? CarbonImmutable::today();
        $created = 0;

        foreach (RecurringExpense::active()->get() as $schedule) {
            $created += $this->forSchedule($schedule, $today);
        }

        return $created;
    }

    /** Roll one schedule from today, for the screen that just created it. */
    public function forScheduleNow(RecurringExpense $schedule): int
    {
        return $this->forSchedule($schedule, CarbonImmutable::today());
    }

    public function forSchedule(RecurringExpense $schedule, CarbonImmutable $today): int
    {
        $created = 0;
        $months = $schedule->frequency->months();

        // ⚠ Walk from the schedule's own start, not from today. A subscription
        // entered in September that began in June should show June, July and
        // August as due - they are, and nobody paid them through this system.
        $cursor = CarbonImmutable::parse($schedule->start_date)->startOfMonth();
        $ceiling = $today->addMonths(self::HORIZON * $months)->endOfMonth();
        $end = $schedule->end_date === null ? null : CarbonImmutable::parse($schedule->end_date);

        while ($cursor <= $ceiling) {
            $due = $schedule->frequency->dateIn($cursor, (int) $schedule->day_of_month);

            // ⚠ The DUE DATE decides, not the month. A cost starting on the 20th
            // does not owe anything for a month whose due date fell before it.
            if ($due->lt(CarbonImmutable::parse($schedule->start_date))) {
                $cursor = $cursor->addMonths($months);

                continue;
            }

            if ($end !== null && $due->gt($end)) {
                break;
            }

            $made = RecurringExpenseOccurrence::once(
                sprintf('recurring:%d:%s', $schedule->id, $due->format('Y-m')),
                [
                    'business_id' => $schedule->business_id,
                    'recurring_expense_id' => $schedule->id,
                    'due_on' => $due->toDateString(),
                    'period_label' => $due->format('F Y'),
                    // Copied, never joined: a price rise must not restate what
                    // an earlier month expected.
                    'expected_amount' => $schedule->amount,
                ],
            );

            $created += $made === null ? 0 : 1;
            $cursor = $cursor->addMonths($months);
        }

        return $created;
    }
}
