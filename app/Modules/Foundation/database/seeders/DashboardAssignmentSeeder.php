<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\DashboardRoleAssignment;
use App\Support\Dashboards\DashboardKey;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The default dashboard for each role that exists, matched by name (Owner,
 * Finance, Manager, Staff, Self-service). Idempotent and additive: a role that
 * already has an assignment keeps it, so re-seeding never overwrites a choice
 * made on Settings > Dashboards. Same defaults as the 2026_10_11_200002
 * migration, for a fresh install.
 */
class DashboardAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::query()->whereNotNull('business_id')->get(['id', 'name', 'business_id']);

        foreach ($roles as $role) {
            $default = DashboardKey::defaultForRoleName($role->name);

            if ($default === null) {
                continue;
            }

            DashboardRoleAssignment::withoutGlobalScopes()->firstOrCreate(
                ['role_id' => $role->id],
                ['business_id' => $role->business_id, 'dashboard' => $default->value],
            );
        }
    }
}
