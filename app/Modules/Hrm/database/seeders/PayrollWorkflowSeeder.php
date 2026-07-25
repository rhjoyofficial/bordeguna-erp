<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Approval chains for payroll (WFL-07) and for reopening a locked one (WFL-08).
 *
 * **Both are defined by permission, not role.** WFL-08 names HR Head, Finance
 * and Management, but only Finance exists in this system — HR Head and
 * Management do not. Rather than invent an organisational structure, Joy chose
 * (2026-07-25) to express each stage as a permission, seeded to Owner so the
 * chain works immediately, and bound to whatever real roles emerge later
 * through the existing Roles screen. No code changes when that happens.
 *
 * Finance already exists as a role, so it also receives its own unlock
 * permission out of the box — the one stage of the three the questionnaire's
 * org structure can actually fill today.
 */
class PayrollWorkflowSeeder extends Seeder
{
    /** @var array<int, string> */
    private const PERMISSIONS = [
        'payroll.unlock.hr',
        'payroll.unlock.finance',
        'payroll.unlock.management',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            // WFL-07: payroll approval on the shared pipeline. A single stage —
            // the questionnaire asks for approval, not a chain, at this point.
            $this->seed($business->id, 'payroll_run', 'Payroll approval', [
                [
                    'sequence' => 1,
                    'name' => 'Payroll approval',
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => ['hrm.payroll.approve'],
                ],
            ]);

            // WFL-08's three authorities, in order.
            $this->seed($business->id, 'payroll_unlock', 'Payroll unlock', [
                [
                    'sequence' => 1,
                    'name' => 'HR Head',
                    'allowed_roles' => [],
                    'allowed_permissions' => ['payroll.unlock.hr'],
                ],
                [
                    'sequence' => 2,
                    'name' => 'Finance',
                    'allowed_roles' => [],
                    'allowed_permissions' => ['payroll.unlock.finance'],
                ],
                [
                    'sequence' => 3,
                    'name' => 'Management',
                    'allowed_roles' => [],
                    'allowed_permissions' => ['payroll.unlock.management'],
                ],
            ]);

            $owner = Role::where('business_id', $business->id)->where('name', 'Owner')->first();
            $owner?->givePermissionTo(self::PERMISSIONS);

            // The one authority that already exists as a role.
            $finance = Role::where('business_id', $business->id)->where('name', 'Finance')->first();
            $finance?->givePermissionTo('payroll.unlock.finance');
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
