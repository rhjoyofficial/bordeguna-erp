<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Manages who belongs to the current business and which role(s) they hold
 * there. Roles are per-business (spatie teams, team key = business_id), so
 * every operation here is implicitly scoped to the current business by the
 * team context ResolveCurrentBusiness already set for the request.
 *
 * Users themselves are NOT business-scoped models (a person can belong to
 * several businesses — the central-employer model), so route-bound users are
 * explicitly membership-checked before any action, never assumed in-scope.
 */
class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();

        // Load the business from the resolved tenant context, not via the
        // acting user's membership — a Super Admin managing a team need not be
        // a member of it (access is already gated above).
        $members = Business::findOrFail($businessId)
            ->users()->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'is_super_admin' => $user->is_super_admin,
                // Team-aware: getRoleNames() returns only the current business's roles.
                'roles' => $user->getRoleNames()->values(),
                'is_self' => $user->id === $request->user()->id,
            ]);

        return Inertia::render('settings/team', [
            'members' => $members,
            'assignable_roles' => Role::where('business_id', $businessId)->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('business_id', $businessId)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->businesses()->attach($businessId);
        $user->assignRole($data['roles']); // team-aware → current business

        return back()->with('success', "{$user->name} added to the team.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureMember($user, $businessId);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('business_id', $businessId)],
        ]);

        // Guard: don't let the last Owner lose the Owner role and orphan the business.
        if ($this->wouldRemoveLastOwner($user, $data['roles'])) {
            return back()->with('error', 'This is the only Owner — assign another Owner before changing this role.');
        }

        $user->syncRoles($data['roles']); // team-aware → current business

        return back()->with('success', "{$user->name}'s roles updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureMember($user, $businessId);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove yourself from the business.');
        }

        if ($this->wouldRemoveLastOwner($user, [])) {
            return back()->with('error', 'This is the only Owner — assign another Owner before removing this user.');
        }

        $user->syncRoles([]); // drop this business's roles
        $user->businesses()->detach($businessId);

        return back()->with('success', "{$user->name} removed from the team.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureMember($user, $businessId);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        // Deactivation blocks login everywhere; don't let it strand a business
        // with no active Owner. (Global deactivation semantics for multi-business
        // users get revisited when the central-employee model lands in Phase 1.)
        if ($user->is_active && $user->hasRole('Owner') && User::role('Owner')->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'This is the only active Owner — cannot deactivate.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', $user->is_active ? "{$user->name} reactivated." : "{$user->name} deactivated.");
    }

    private function ensureMember(User $user, int $businessId): void
    {
        abort_unless($user->businesses()->where('businesses.id', $businessId)->exists(), 404);
    }

    /**
     * True when $user currently holds Owner, the new role set drops it, and no
     * other user in this business holds Owner.
     */
    private function wouldRemoveLastOwner(User $user, array $newRoles): bool
    {
        if (! $user->hasRole('Owner') || in_array('Owner', $newRoles, true)) {
            return false;
        }

        return User::role('Owner')->count() <= 1;
    }
}
