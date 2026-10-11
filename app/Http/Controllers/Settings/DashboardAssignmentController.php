<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\DashboardRoleAssignment;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Settings > Dashboards: which dashboard each role of THIS business lands on.
 * Owner only (dashboard.view_all): deciding who sees which dashboard belongs to
 * the person who can see them all.
 *
 * ⚠ "Automatic" (no row) leaves the role to the permission fallback in
 * DashboardAccess, which is what a custom role gets until somebody decides.
 */
class DashboardAssignmentController extends Controller
{
    public function index(DashboardAccess $access, BusinessContext $context): Response
    {
        $this->authorize(DashboardAccess::VIEW_ALL);

        $roles = Role::query()->where('business_id', $context->id())->with('permissions:id,name')->orderBy('id')->get();
        $assigned = DashboardRoleAssignment::query()->whereIn('role_id', $roles->pluck('id'))->pluck('dashboard', 'role_id');

        return Inertia::render('settings/dashboards', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'users' => $role->users()->count(),
                'dashboard' => $assigned[$role->id] ?? null,
                'automatic' => $access->fallbackFor($role)->value,
            ])->values()->all(),
            'dashboards' => collect(DashboardKey::ordered())->map(fn (DashboardKey $key) => [
                'value' => $key->value,
                'label' => $key->label(),
                'description' => $key->description(),
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, BusinessContext $context): RedirectResponse
    {
        $this->authorize(DashboardAccess::VIEW_ALL);

        $roleIds = Role::query()->where('business_id', $context->id())->pluck('id')->all();

        $data = $request->validate([
            'assignments' => ['required', 'array'],
            'assignments.*.role_id' => ['required', 'integer', Rule::in($roleIds)],
            'assignments.*.dashboard' => ['nullable', Rule::in(DashboardKey::values())],
        ]);

        foreach ($data['assignments'] as $row) {
            if ($row['dashboard'] === null || $row['dashboard'] === '') {
                DashboardRoleAssignment::query()->where('role_id', $row['role_id'])->delete();

                continue;
            }

            DashboardRoleAssignment::query()->updateOrCreate(
                ['role_id' => $row['role_id']],
                ['business_id' => $context->id(), 'dashboard' => $row['dashboard']],
            );
        }

        activity('dashboard')
            ->causedBy($request->user())
            ->withProperties(['assignments' => $data['assignments']])
            ->log('Changed which dashboard each role lands on');

        return back()->with('success', 'Dashboards saved.');
    }
}
