<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): the second person for an
 * HR cancellation or undo (a leave or movement cancelled after that month's
 * payroll was approved; a separation undone). One stage: the Owner, a Manager,
 * or anybody holding HR's permissions. The requester is refused by
 * HrCorrectionRequest itself, so HR asks and somebody else confirms.
 *
 * The same row is planted on a live database by the 2026_10_24_100000
 * migration; this seeder is for a fresh install and for tests.
 */
class HrCorrectionWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'hr_correction_request'],
                ['name' => 'HR cancellation or undo (second person)', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->create([
                'sequence' => 1,
                'name' => 'Second person',
                'allowed_roles' => ['Owner', 'Manager'],
                'allowed_permissions' => ['hrm.leave.manage', 'hrm.employees.manage'],
            ]);
        }
    }
}
