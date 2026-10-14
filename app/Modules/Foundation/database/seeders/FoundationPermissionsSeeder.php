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
            'files.view',
            'files.manage',
            'chat.use',
            // ⚠ Owner ONLY by default, and deliberately not given to Manager or
            // Staff below. Every message this permission allows spends money on
            // a metered API key, so it is granted deliberately from Settings >
            // Roles rather than arriving with a job title.
            'foundation.ai.use',
            // Role dashboards (11 Oct 2026): open every dashboard. Audited:
            // a view only this permission allows is written to the activity log.
            'dashboard.view_all',
            // Settings > Failed jobs (11 Oct 2026): read, retry and forget the
            // queue's failed jobs. System-wide, so Owner only.
            'system.jobs.manage',
            // Settings > Sounds and Settings > Popups (14 Oct 2026). Group-wide.
            'system.popups.manage',
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
            'files.view',
            'files.manage',
            'chat.use',
        ],
        // ⚠ Notes follow the calendar to every role for the same reason: a board
        // only some of the team can read is one nobody writes on. Privacy is
        // handled per note, not per role.
        // ⚠ Staff may READ the library and may NOT manage it. `files.view` on
        // its own shows a Staff login the library and nothing else, because
        // FileVisibility hands back only the types whose own permission they
        // already hold.
        'Staff' => [
            'calendar.view',
            'calendar.manage',
            'notes.view',
            'notes.manage',
            'files.view',
            // ⚠ One permission, not a view/manage pair. Chat is either something
            // you take part in or it is not; a read-only member of a
            // conversation is not a thing anybody asked for.
            'chat.use',
        ],
    ];

    /**
     * ⚠⚠ CREATED BUT GRANTED TO NOBODY (Joy, 30 Aug 2026). These exist so they
     * can be handed out on Settings > Roles, and until somebody does the
     * assistant can do precisely nothing - it cannot read a single row, touch a
     * note or add a to-do, even for an Owner.
     *
     * ⛔ Deliberately NOT added to ROLE_PERMISSIONS above. Putting them on Owner
     * "so it works out of the box" would undo the whole point: the default is
     * that an AI can do nothing, and every capability is a decision somebody
     * made on purpose.
     *
     * @var list<string>
     */
    private const UNGRANTED_PERMISSIONS = [
        'foundation.ai.read',
        'foundation.ai.notes',
        'foundation.ai.todos',
    ];

    public function run(): void
    {
        foreach (self::UNGRANTED_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

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
