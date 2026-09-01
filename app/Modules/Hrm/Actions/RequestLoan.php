<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\LoanSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a loan or salary advance request (ADJ-07's first ticked item).
 *
 * Nothing is disbursed here and no money is owed yet — a pending loan has no
 * ledger entries at all, so an employee's outstanding balance is unaffected
 * until somebody approves it. That is the same discipline ADJ-06 imposed on an
 * increment: a proposal is not a fact.
 *
 * The terms are validated now rather than at approval, so an approver is
 * deciding whether to lend rather than discovering that the schedule was
 * impossible all along.
 */
class RequestLoan
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        LoanType $type,
        float $principal,
        float $installmentAmount,
        int $startYear,
        int $startMonth,
        string $reason,
        User $requestedBy,
    ): Loan {
        $businessId = $this->owningBusinessId($employee);

        $this->guardTerms($principal, $installmentAmount);
        $this->guardStartMonth($startYear, $startMonth);
        $this->guardNoOpenLoan($employee, $type);

        return DB::transaction(function () use (
            $employee, $type, $principal, $installmentAmount,
            $startYear, $startMonth, $reason, $requestedBy, $businessId
        ) {
            $loan = Loan::create([
                'business_id' => $businessId,
                'employee_id' => $employee->id,
                'reference' => Loan::nextReference($businessId),
                'type' => $type,
                'status' => LoanStatus::Pending,
                'principal' => round($principal, 2),
                'installment_amount' => round($installmentAmount, 2),
                'installment_count' => LoanSchedule::count($principal, $installmentAmount),
                'start_year' => $startYear,
                'start_month' => $startMonth,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $loan->submitForApproval($requestedBy);
            $this->notify->handle($approval, $loan->summaryLine());

            return $loan->fresh();
        });
    }

    /**
     * Correction C7c — the lending business is the one paying the employee,
     * since it is the one whose payroll will recover the money.
     *
     * @throws ValidationException
     */
    private function owningBusinessId(Employee $employee): int
    {
        $assignment = $employee->currentAssignment();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no active primary assignment, so no business would recover the loan.',
            ]);
        }

        return $assignment->business_id;
    }

    /**
     * @throws ValidationException
     */
    private function guardTerms(float $principal, float $installmentAmount): void
    {
        if ($principal <= 0) {
            throw ValidationException::withMessages(['principal' => 'A loan must be for more than zero.']);
        }

        if ($installmentAmount <= 0) {
            throw ValidationException::withMessages(['installment_amount' => 'An installment must be more than zero.']);
        }

        if ($installmentAmount > $principal) {
            // Not a real schedule — it would be a single payment of less than
            // the stated installment, which is confusing rather than wrong.
            throw ValidationException::withMessages([
                'installment_amount' => 'The installment cannot exceed the principal. For a single repayment, set the installment equal to the principal.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardStartMonth(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages(['start_month' => 'That is not a month.']);
        }

        $period = PayrollPeriod::where('year', $year)->where('month', $month)->first();

        // ⚠⚠ THE TEST IS WHETHER THAT SALARY HAS BEEN PAID, NOT WHETHER THE
        // CALENDAR HAS MOVED ON. This used to refuse any month before the
        // current one, and on 1 September 2026 that refused the August payroll
        // Joy was still holding open - three advances handed out in August had
        // nowhere to be recovered from. A payroll month stays recoverable for
        // as long as nobody has been paid; only then is it too late, and WFL-06
        // sends the difference forward as an arrear instead.
        if ($period?->latestRun()?->status?->isPaidOut() === true) {
            throw ValidationException::withMessages([
                'start_month' => sprintf(
                    'The %s salary has already been paid, so nothing can be recovered from it. Start the repayment in a later month.',
                    $period->label(),
                ),
            ]);
        }

        // A past month with no payroll period at all will never be run, so a
        // schedule starting there would wait for a salary that is not coming.
        if ($period === null && Carbon::create($year, $month, 1)->startOfDay()->lt(Carbon::today()->startOfMonth())) {
            throw ValidationException::withMessages([
                'start_month' => 'That month has no payroll to recover from. Start the repayment in a month that still has a salary to come.',
            ]);
        }
    }

    /**
     * One open loan of each kind at a time.
     *
     * ADJ-07 does not say this outright, but it follows from what it does say:
     * an installment and a remaining balance are stated in the singular, and two
     * concurrent advances would deduct twice from the same salary with no stated
     * rule for which takes precedence when the net runs short.
     *
     * @throws ValidationException
     */
    private function guardNoOpenLoan(Employee $employee, LoanType $type): void
    {
        $open = Loan::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('type', $type)
            ->whereIn('status', [LoanStatus::Pending, LoanStatus::Active])
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'employee_id' => sprintf(
                    'This employee already has an open %s. Settle it before raising another.',
                    strtolower($type->label()),
                ),
            ]);
        }
    }
}
