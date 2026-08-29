<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Who says yes to an employee asking for their money back.
 *
 * ⚠⚠ WITHOUT THIS ROW THE WHOLE MODULE IS DEAD, AND SILENTLY. `submitForApproval`
 * attaches no workflow when none exists, `NotifyStageApprovers` is a documented
 * no-op with no stage to read, and the approval inbox only lists what the
 * current stage says the reader may act on. So the claim is saved, the employee
 * is told it was sent for approval, and it reaches nobody: it sits Pending for
 * ever, and settlement refuses it because it was never approved. Every screen
 * involved looks like it worked.
 *
 * ⚠ ONE STAGE, and it is the payroll permission - the same people who settle.
 * Reimbursement is a payroll act here, not a line-manager one, which matches how
 * the settlement screen is gated and how Joy's roles are actually set up (HR and
 * Payroll on one person). A manager stage would be inventing an approval step
 * nobody asked for, and on a nine-person business it would mostly mean waiting
 * on somebody who has no view of the money.
 *
 * ⚠ Owner is named as a role as well as the permission, exactly as leave does,
 * so a business that has not handed out the payroll permission yet is not left
 * with claims nobody can action.
 *
 * Idempotent: safe to re-run; an existing workflow and its stages are untouched.
 */
class ExpenseClaimWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'expense_claim'],
                ['name' => 'Expense claim', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->createMany([
                [
                    'sequence' => 1,
                    'name' => 'Payroll',
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => ['hrm.payroll.manage'],
                ],
            ]);
        }
    }
}
