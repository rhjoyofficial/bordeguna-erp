<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The approval chain for attendance corrections (COR-03).
 *
 * COR-03 ticked "Reporting Manager", "Manager then HR" and named Super Admin —
 * the fullest of those is Manager → HR, which is what this seeds; Super Admin
 * passes any stage through the engine's existing god-mode check.
 *
 * Known fidelity limit: the workflow engine authorises a stage by role or
 * permission, not by "this employee's own reporting manager". Stage 1 therefore
 * means *a* Manager rather than *their* manager. Routing to the specific
 * supervisor would need dynamic per-request stage resolution in the engine,
 * which is deliberately out of scope here — recorded as an open item instead.
 */
class AttendanceCorrectionWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'attendance_correction'],
                ['name' => 'Attendance correction', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->createMany([
                [
                    'sequence' => 1,
                    'name' => 'Manager',
                    'allowed_roles' => ['Manager', 'Owner'],
                    'allowed_permissions' => ['hrm.attendance.approve'],
                ],
                [
                    'sequence' => 2,
                    'name' => 'HR',
                    'allowed_roles' => [],
                    'allowed_permissions' => ['hrm.attendance.manage'],
                ],
            ]);

            // COR-03's manager stage needs someone able to fill it out of the box.
            $manager = Role::where('business_id', $business->id)->where('name', 'Manager')->first();
            $manager?->givePermissionTo('hrm.attendance.approve');
        }
    }
}
