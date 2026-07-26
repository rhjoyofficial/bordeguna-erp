<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Approval chains for the four things Phase 3d lets somebody ask payroll to pay:
 * overtime (ADJ-01), a bonus (ADJ-05), a manual adjustment (ADJ-09) and an
 * increment (ADJ-06).
 *
 * All four are seeded as a **single stage on `hrm.payroll.approve`**, and both
 * halves of that are deliberate.
 *
 * *Single stage*, because the questionnaire asks for approval and never
 * describes a chain for any of them. Inventing a Manager → HR → Finance ladder
 * would be inventing an organisation. The workflow engine supports chains
 * already, so lengthening one later is configuration rather than a release.
 *
 * *`hrm.payroll.approve` rather than `hrm.payroll.manage`*, so the person who
 * raises an adjustment is not automatically the person who approves it — the
 * same separation of preparer and approver WFL-07 asked for on the payroll run
 * itself. It matters most here: an adjustment is the one place in payroll where
 * an arbitrary sum can be moved by hand.
 */
class PayrollInputWorkflowSeeder extends Seeder
{
    /** @var array<string, string> */
    private const WORKFLOWS = [
        'overtime_assignment' => 'Overtime approval',
        'bonus_award' => 'Bonus approval',
        'payroll_adjustment' => 'Payroll adjustment approval',
        'salary_revision' => 'Salary revision approval',
        // ADJ-07's first ticked item. A loan is money leaving the company, so
        // the preparer/approver split matters here for the same reason it does
        // on an adjustment.
        'loan' => 'Loan and advance approval',
    ];

    public function run(): void
    {
        foreach (Business::all() as $business) {
            foreach (self::WORKFLOWS as $key => $name) {
                $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'key' => $key],
                    ['name' => $name, 'is_active' => true],
                );

                if ($workflow->stages()->exists()) {
                    continue;
                }

                $workflow->stages()->create([
                    'sequence' => 1,
                    'name' => $name,
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => ['hrm.payroll.approve'],
                ]);
            }
        }
    }
}
