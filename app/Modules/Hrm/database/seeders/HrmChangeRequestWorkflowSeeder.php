<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the employee change-request approval workflows per Joy's policy
 * (2026-07-21): personal/contact changes need HR; bank / MFS changes need HR
 * then Finance. HR is expressed as the existing hrm.employees.manage permission;
 * Finance as a seeded Finance role (granted to Owners so there's an approver
 * out of the box). All configurable later via the workflow admin UI.
 *
 * Idempotent: existing workflows/stages are left untouched.
 */
class HrmChangeRequestWorkflowSeeder extends Seeder
{
    private const WORKFLOWS = [
        'employee_personal_change' => [
            'name' => 'Employee contact-detail change',
            'stages' => [
                ['name' => 'HR', 'roles' => [], 'permissions' => ['hrm.employees.manage']],
            ],
        ],
        'employee_bank_change' => [
            'name' => 'Employee bank / MFS change',
            'stages' => [
                ['name' => 'HR', 'roles' => [], 'permissions' => ['hrm.employees.manage']],
                ['name' => 'Finance', 'roles' => ['Finance'], 'permissions' => []],
            ],
        ],
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (Business::all() as $business) {
            // A Finance approver role for the second bank-change stage, granted
            // to Owners so the flow works before HR sets up dedicated approvers.
            $registrar->setPermissionsTeamId($business->id);
            Role::firstOrCreate(['name' => 'Finance', 'business_id' => $business->id, 'guard_name' => 'web']);
            Role::where('business_id', $business->id)->where('name', 'Owner')->first()?->users->each->assignRole('Finance');

            foreach (self::WORKFLOWS as $key => $definition) {
                $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->firstOrCreate(
                    ['business_id' => $business->id, 'key' => $key],
                    ['name' => $definition['name'], 'is_active' => true],
                );

                if ($workflow->stages()->count() > 0) {
                    continue;
                }

                foreach ($definition['stages'] as $index => $stage) {
                    $workflow->stages()->create([
                        'sequence' => $index + 1,
                        'name' => $stage['name'],
                        'allowed_roles' => $stage['roles'],
                        'allowed_permissions' => $stage['permissions'],
                    ]);
                }
            }
        }

        $registrar->setPermissionsTeamId(null);
    }
}
