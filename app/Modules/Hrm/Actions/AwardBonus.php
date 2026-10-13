<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\BonusEvent;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Awards a bonus event to employees (ADJ-05).
 *
 * The event carries the rule; this resolves it into a figure per person and
 * routes each for approval. Two decisions are worth stating.
 *
 * **The amount is resolved now and stored.** A percentage bonus is measured
 * against the salary in force at the *end of the event's payroll period*, and
 * the answer is written down. Recomputing it later would let an increment in
 * September quietly restate what an Eid bonus in June was worth — the same
 * mistake retroactive payroll makes, and the same reason PAY-12 forbids it.
 *
 * **Each employee gets their own approvable award.** ADJ-05 says a bonus is
 * configured per event, but approving two hundred people as a single indivisible
 * act means one contested case blocks everyone.
 */
class AwardBonus
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @param  Collection<int, Employee>|array<int, Employee>  $employees
     * @return array{awarded: int, skipped: int, total: float}
     *
     * @throws ValidationException
     */
    public function handle(BonusEvent $event, $employees, User $requestedBy): array
    {
        $period = $event->period;

        if ($period === null) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'This bonus event has no payroll month, so there is no payslip for it to land on (ADJ-09).',
            ]);
        }

        // ⚠ A bonus lands on the event's month, which must still take inputs.
        PayrollInputWindow::assertAccepts($period);

        $awarded = 0;
        $skipped = 0;
        $total = 0.0;

        DB::transaction(function () use ($event, $employees, $requestedBy, $period, &$awarded, &$skipped, &$total) {
            foreach ($employees as $employee) {
                $existing = BonusAward::withoutGlobalScopes()
                    ->where('bonus_event_id', $event->id)
                    ->where('employee_id', $employee->id)
                    ->exists();

                if ($existing) {
                    $skipped++;

                    continue;
                }

                $salary = SalaryStructure::activeFor($employee->id, $period->end_date)?->breakdown()
                    ?? SalaryBreakdown::empty();

                $amount = $event->amountFor($salary);

                if ($amount <= 0) {
                    // A percentage of a salary that does not exist is zero, and
                    // a zero-value payslip line explains nothing. Skipping is
                    // honest; the count is reported back.
                    $skipped++;

                    continue;
                }

                $award = BonusAward::create([
                    'bonus_event_id' => $event->id,
                    'employee_id' => $employee->id,
                    'amount' => $amount,
                    'basis_note' => $event->basisNoteFor($salary),
                ]);

                $approval = $award->submitForApproval($requestedBy);
                $this->notify->handle($approval, $award->summaryLine());

                $awarded++;
                $total += $amount;
            }
        });

        return ['awarded' => $awarded, 'skipped' => $skipped, 'total' => round($total, 2)];
    }
}
