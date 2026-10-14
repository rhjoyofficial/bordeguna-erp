<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Actions\SendPasswordSetupLink;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
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
        $business = Business::findOrFail($businessId);

        // A row in password_reset_tokens means a set-password link is out and
        // has NOT been used yet (Laravel deletes the row on use), so this is
        // the honest answer to "did they set their password?", not a guess.
        $pendingLinks = DB::table('password_reset_tokens')
            ->whereIn('email', $business->users()->pluck('users.email'))
            ->pluck('created_at', 'email');

        $mailer = app(SendPasswordSetupLink::class);
        $expiresAfter = $mailer->lifetimeMinutes();

        $members = $business
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
                'password_link_sent_at' => isset($pendingLinks[$user->email])
                    ? Carbon::parse($pendingLinks[$user->email])->timezone(config('app.timezone'))->format('j M Y, g:i a')
                    : null,
                'password_link_expired' => isset($pendingLinks[$user->email])
                    ? Carbon::parse($pendingLinks[$user->email])->addMinutes($expiresAfter)->isPast()
                    : false,
            ]);

        return Inertia::render('settings/team', [
            'members' => $members,
            'assignable_roles' => Role::where('business_id', $businessId)->orderBy('name')->pluck('name'),
            'password_link_lifetime' => $mailer->lifetimeLabel(),
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
        ], [
            // FND-004 (audit 14 Oct 2026). Logins are shared across the group,
            // so the usual clash is a person who already signs in to another
            // business. Laravel's bare "already been taken" left the admin
            // with no next step; this names the path that does attach an
            // existing login (CreateEmployeeLogin). Not attached from here on
            // purpose: typing somebody's email must not pull another
            // business's login into this one.
            'email.unique' => 'This email already has a login, perhaps in another business. To give that person access here, open their employee record in HR and create the login from there (it links the existing login to this business), or ask a Super Admin.',
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

        // ⚠⚠ 14 Oct 2026 (audit #27): `is_active` is ONE switch for the whole
        // group, so a user admin of one business could lock a shared person
        // (an accountant, a manager working for two businesses) out of all of
        // them. Deactivating somebody who belongs to more than one business
        // now needs this business's Owner, or a Super Admin (group-wide).
        // Removing them from THIS team is still open to any user admin.
        $actor = $request->user();

        if ($user->is_active
            && $user->businesses()->count() > 1
            && ! $actor->is_super_admin
            && ! $actor->hasRole('Owner')) {
            return back()->with('error', "{$user->name} also works for another business, and deactivating blocks their login in every business. Only an Owner can do that. To take them off this team only, remove them instead.");
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            // C-SEC-3. The remember cookie re-authenticates WITHOUT passing
            // through the login guard, so an unchanged token would let this
            // user mint fresh sessions after deactivation. Cycled, not
            // cleared: an empty token has matched an empty cookie before.
            $user->setRememberToken(Str::random(60));
            $user->save();

            // C-SEC-2. Revocation is immediate, not next-request: the database
            // session driver makes this user's sessions enumerable, so they
            // are deleted here rather than left for EnsureUserIsActive to
            // reject one by one. Driver-guarded - on any other driver the
            // per-request check is the guarantee instead, which is as far as
            // that architecture allows. Reactivation restores NOTHING; the
            // person logs in afresh.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }
        }

        return back()->with('success', $user->is_active ? "{$user->name} reactivated." : "{$user->name} deactivated.");
    }

    /**
     * Emails this member a link to set their own password.
     *
     * The admin never sees or types the password: the link is the standard
     * password-reset token, so the person choosing the password is the only
     * one who ever knows it. Used both for a brand-new account and for a
     * link that was never opened before it expired.
     */
    public function sendPasswordLink(Request $request, User $user, SendPasswordSetupLink $mailer): RedirectResponse
    {
        $this->authorize('foundation.users.manage');

        $businessId = app(BusinessContext::class)->id();
        $this->ensureMember($user, $businessId);

        if (! $user->is_active) {
            return back()->with('error', "{$user->name} is deactivated. Reactivate them first, or the link leads to a login they cannot pass.");
        }

        $status = $mailer->handle($user);

        return match ($status) {
            PasswordBroker::RESET_LINK_SENT => back()->with(
                'success',
                "Password link emailed to {$user->email}. It is valid for {$mailer->lifetimeLabel()}."
            ),
            PasswordBroker::RESET_THROTTLED => back()->with(
                'error',
                'A link was just sent to this person. Wait a minute before sending another.'
            ),
            SendPasswordSetupLink::MAIL_FAILED => back()->with(
                'error',
                "Could not email {$user->email}. The mail server refused the message. Check the mail settings or try again."
            ),
            default => back()->with('error', "Could not send a link to {$user->email}."),
        };
    }

    private function ensureMember(User $user, int $businessId): void
    {
        abort_unless($user->businesses()->where('businesses.id', $businessId)->exists(), 404);
    }

    /**
     * True when $user currently holds Owner, the new role set drops it, and no
     * other ACTIVE user in this business holds Owner.
     *
     * FND-003 (audit 14 Oct 2026). This used to count every Owner, so a
     * deactivated Owner kept the count at two and the only active Owner could
     * be demoted or removed, leaving nobody able to administer the business.
     * Now the same rule toggleActive() uses: only active Owners count.
     */
    private function wouldRemoveLastOwner(User $user, array $newRoles): bool
    {
        if (! $user->hasRole('Owner') || in_array('Owner', $newRoles, true)) {
            return false;
        }

        return ! User::role('Owner')
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();
    }
}
