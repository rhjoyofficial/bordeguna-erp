<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Approval chains for leave (LEV-05: Manager → HR) and movement (POL-10:
 * manager confirmation).
 *
 * Movement is a single manager stage because POL-10 asks only for the manager's
 * confirmation — routing it through HR as well would invent a step the
 * questionnaire does not ask for.
 *
 * Same known limit as corrections: a stage means *a* Manager, not *that
 * employee's* reporting manager, until the workflow engine gains dynamic
 * approval targets (tracked as an open item).
 */
class LeaveWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $this->seed($business->id, 'leave_request', 'Leave request', [
                [
                    'sequence' => 1,
                    'name' => 'Manager',
                    'allowed_roles' => ['Manager', 'Owner'],
                    'allowed_permissions' => ['hrm.leave.approve'],
                ],
                [
                    'sequence' => 2,
                    'name' => 'HR',
                    'allowed_roles' => [],
                    'allowed_permissions' => ['hrm.leave.manage'],
                ],
            ]);

            $this->seed($business->id, 'movement', 'Movement', [
                [
                    'sequence' => 1,
                    'name' => 'Manager',
                    'allowed_roles' => ['Manager', 'Owner'],
                    'allowed_permissions' => ['hrm.leave.approve'],
                ],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function seed(int $businessId, string $key, string $name, array $stages): void
    {
        $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
            ['business_id' => $businessId, 'key' => $key],
            ['name' => $name, 'is_active' => true],
        );

        if ($workflow->stages()->exists()) {
            return;
        }

        $workflow->stages()->createMany($stages);
    }
}
