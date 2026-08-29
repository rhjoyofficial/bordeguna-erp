<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Platform-level permissions owned by Foundation (user & role management),
 * as opposed to the domain permissions each business module seeds. Same
 * shape as the module permission seeders: permissions are global rows,
 * granted per business through that business's own Role. Super Admins do
 * not need these — they bypass every gate (see FoundationServiceProvider).
 */
class FoundationPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        // Managing who belongs to a business, who holds which role, and the
        // organization structure are Owner-level responsibilities. They are
        // separate permissions so an HR-style role can later be granted org
        // management without user-access management.
        'Owner' => [
            'foundation.users.manage',
            'foundation.organization.manage',
            'calendar.view',
            'calendar.manage',
            'notes.view',
            'notes.manage',
        ],
        // ⚠⚠ The calendar goes to EVERY role (29 Aug 2026), which is unusual on
        // this project and deliberate. It is the one screen whose value is that
        // the whole team looks at the same one, and a warehouse hand needs to
        // know the office is shut on Thursday as much as the Owner does.
        //
        // ⚠ Granting it leaks nothing, because CalendarFeed gates each SOURCE by
        // that module's own permission: somebody without sales.reports.view is
        // never handed an invoice due date to begin with. The permission on this
        // page opens the grid; it does not decide what is drawn on it.
        'Manager' => [
            'calendar.view',
            'calendar.manage',
            'notes.view',
            'notes.manage',
        ],
        // ⚠ Notes follow the calendar to every role for the same reason: a board
        // only some of the team can read is one nobody writes on. Privacy is
        // handled per note, not per role.
        'Staff' => [
            'calendar.view',
            'calendar.manage',
            'notes.view',
            'notes.manage',
        ],
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $role = Role::where('business_id', $business->id)->where('name', $roleName)->first();

                // Additive — never syncPermissions (would wipe other modules' grants).
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
