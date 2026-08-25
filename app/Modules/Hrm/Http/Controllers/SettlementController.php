<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\SettlementFigures;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The final settlement statement for somebody who has left.
 *
 * ⚠⚠ WHY THIS CAN EXIST NOW AND COULD NOT BEFORE. EMP-08 approved a final
 * settlement and no phase built one, because a settlement has to price unused
 * leave and the encashment RATE is a rule the questionnaire never states
 * (C10b). The worksheet report was shipped in its place and deliberately adds
 * nothing up. Joy has since switched encashment OFF entirely, so there is no
 * unpriced component left: what remains is salary already earned and a loan
 * already lent, both of them ledger facts. A total made only of facts is
 * arithmetic, not a new rule.
 *
 * ⚠⚠ AND IT CHECKS THAT EVERY TIME. If a leave type is ever marked encashable
 * again, this screen stops totalling and says why, rather than quietly pricing
 * days at a rate nobody agreed. A policy true on the day the code was written
 * is not a policy the code may assume for ever.
 *
 * ⛔ IT PAYS NOBODY. The statement states the position; money moves through the
 * disbursement path that already exists, with its own approvals. A screen that
 * both decides an amount and pays it is one click from an unreviewed payment.
 */
class SettlementController extends Controller
{
    public function show(Employee $employee): Response
    {
        $this->authorize('hrm.payroll.manage');

        // The employee list is tenant-scoped through assignments, the same way
        // every other employee screen resolves who is "ours".
        abort_unless($employee->assignments()->exists(), 404);

        $figures = SettlementFigures::for($employee);
        $encashmentIsLive = SettlementFigures::anyLeaveIsEncashable();

        // Positive: the business owes them. Negative: they owe the business,
        // which is a real outcome when a loan outruns the last payslip, and it
        // is said in those words rather than shown as a negative payable.
        $net = round($figures['unpaid_salary'] - $figures['loan_outstanding'], 2);

        return Inertia::render('hrm/settlement/show', [
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'status' => $employee->status->value,
                'has_left' => $employee->status === EmployeeStatus::Separated,
                'separation_type' => $employee->separation_type?->label(),
                'separation_date' => $employee->separation_date?->toDateString(),
                'joining_date' => $employee->joining_date?->toDateString(),
            ],
            'figures' => $figures,
            'net' => $net,
            // ⚠ The screen never works out on its own whether it may total. The
            // server decides, because the reason is a policy question.
            'can_total' => ! $encashmentIsLive,
            'encashment_is_live' => $encashmentIsLive,
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }
}
