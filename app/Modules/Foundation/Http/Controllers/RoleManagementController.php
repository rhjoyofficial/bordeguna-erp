<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Lets a business define its own roles and choose what each may do — the
 * permission-based RBAC the HRM authority requires (ACL-01), so roles like
 * "HR Admin" or "Payroll Officer" can be created without a code change.
 *
 * Roles are per-business (spatie teams, team key = business_id); permissions
 * are global rows shared across businesses. The three seeded roles
 * (Owner/Manager/Staff) are structural — code and seeders reference them by
 * name — so they cannot be renamed or deleted, though their permissions stay
 * editable. The Owner role always keeps foundation.users.manage so a business
 * can never strip its own ability to administer access.
 */
class RoleManagementController extends Controller
{
    private const SYSTEM_ROLES = ['Owner', 'Manager', 'Staff'];

    private const OWNER_LOCKED_PERMISSION = 'foundation.users.manage';

    public function index(): Response
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();

        $roles = Role::where('business_id', $businessId)->with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => in_array($role->name, self::SYSTEM_ROLES, true),
                'locked_permissions' => $role->name === 'Owner' ? [self::OWNER_LOCKED_PERMISSION] : [],
                'permissions' => $role->permissions->pluck('name')->values(),
                'members_count' => $role->users()->count(),
            ]);

        return Inertia::render('settings/roles', [
            'roles' => $roles,
            'available_permissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('business_id', $businessId)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => $data['name'], 'business_id' => $businessId, 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('success', "Role \"{$role->name}\" created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureInBusiness($role, $businessId);

        $isSystem = in_array($role->name, self::SYSTEM_ROLES, true);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where('business_id', $businessId)->ignore($role->id),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        // System role names are referenced by code/seeders — keep them fixed.
        if (! $isSystem && $data['name'] !== $role->name) {
            $role->update(['name' => $data['name']]);
        }

        $permissions = $data['permissions'] ?? [];

        // A business can never strip its own ability to administer access.
        if ($role->name === 'Owner' && ! in_array(self::OWNER_LOCKED_PERMISSION, $permissions, true)) {
            $permissions[] = self::OWNER_LOCKED_PERMISSION;
        }

        $role->syncPermissions($permissions);

        return back()->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureInBusiness($role, $businessId);

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return back()->with('error', "\"{$role->name}\" is a built-in role and cannot be deleted.");
        }

        $memberCount = $role->users()->count();
        if ($memberCount > 0) {
            $noun = $memberCount === 1 ? 'member' : 'members';

            return back()->with('error', "Reassign this role's {$memberCount} {$noun} before deleting it.");
        }

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    private function ensureInBusiness(Role $role, int $businessId): void
    {
        abort_unless((int) $role->business_id === $businessId, 404);
    }
}
