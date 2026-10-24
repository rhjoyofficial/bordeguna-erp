<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Notifications\ConfirmNewEmailNotification;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a login put right (FINAL-PLAN 3.5,
 * "User (login)"; 8.1, 21).
 *
 *   Fix name and email   before the first sign-in: any user admin, at once
 *                        (a typo'd address blocks onboarding, S9). After
 *                        it: the Owner only, and the NEW address confirms by
 *                        a signed one-time link before it replaces the old.
 *                        Either way every set-password link already sent to
 *                        the old address stops working.
 *   Delete login         only a login that never signed in, belongs to this
 *                        business alone, is nobody's employee login and that
 *                        nothing points at (User::whyNotRemovable): to the
 *                        Recycle bin, its email still reserved. Everyone
 *                        else is deactivated (toggle-active).
 *
 * ⚠ Users are not tenant-scoped: every route-bound user is membership-checked
 * first, as UserManagementController does. Logged with before and after.
 */
class UserLoginController extends Controller
{
    use ValidatesActionReason;

    /** The confirmation link lives this many hours. */
    public const CONFIRM_HOURS = 48;

    public function fixEmail(Request $request, User $user): RedirectResponse
    {
        $this->authorize('foundation.users.manage');
        $this->ensureMember($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            ...$this->actionReasonRules('fix_details'),
        ], $this->actionReasonMessages());

        $email = mb_strtolower(trim($data['email']));
        $actor = $request->user();
        $signedIn = $user->hasSignedIn();

        if ($signedIn && $email !== $user->email && ! Owners::is($actor)) {
            throw ValidationException::withMessages(['email' => "{$user->name} has signed in before, so only the Owner can change the email."]);
        }

        if ($email !== $user->email) {
            $taken = User::withTrashed()->where('email', $email)->whereKeyNot($user->id)->first()
                ?? User::query()->where('pending_email', $email)->whereKeyNot($user->id)->first();

            if ($taken !== null) {
                throw ValidationException::withMessages(['email' => $taken->trashed()
                    ? 'A deleted login has this email. Ask the Owner to restore it from the Recycle bin.'
                    : 'This email already belongs to another login.']);
            }
        }

        $reason = $this->actionReason($request);
        $before = ['name' => $user->name, 'email' => $user->email];

        if (! $signedIn || $email === $user->email) {
            DB::transaction(function () use ($user, $data, $email, $before) {
                $user->forceFill(['name' => $data['name'], 'email' => $email, 'pending_email' => null, 'pending_email_token' => null, 'pending_email_sent_at' => null, 'pending_email_by' => null])->save();

                if ($email !== $before['email']) {
                    // Every link sent to the wrong address stops working.
                    DB::table('password_reset_tokens')->where('email', $before['email'])->delete();
                }
            });

            CorrectionLog::event('fixed_details', $actor, $user, ['reason' => $reason->display(), 'before' => $before, 'after' => ['name' => $user->name, 'email' => $user->email]]);

            return back()->with('success', $email !== $before['email']
                ? "{$user->name}'s login is now {$email}. Send them a new password link."
                : "{$user->name}'s name is fixed.");
        }

        // Signed in before: the name changes now, the email only once the new
        // address confirms.
        $token = Str::random(48);
        $until = now()->addHours(self::CONFIRM_HOURS);

        $user->forceFill([
            'name' => $data['name'],
            'pending_email' => $email,
            'pending_email_token' => hash('sha256', $token),
            'pending_email_sent_at' => now(),
            'pending_email_by' => $actor->id,
        ])->save();

        $url = URL::temporarySignedRoute('users.email.confirm', $until, ['user' => $user->id, 'token' => $token]);

        try {
            Notification::route('mail', $email)->notifyNow(new ConfirmNewEmailNotification($user->name, $url, $until->format('j M Y, g:i A')));
        } catch (Throwable) {
            return back()->with('error', "Could not email {$email}. The mail server refused the message. The old address stays until the new one is confirmed.");
        }

        CorrectionLog::event('email_change_requested', $actor, $user, ['reason' => $reason->display(), 'before' => $before, 'pending_email' => $email]);

        return back()->with('success', "A confirmation link went to {$email}. The login changes when they click it; until then it stays {$user->email}.");
    }

    /** The new address clicked the link: swap, and stop the old address's links. */
    public function confirmEmail(Request $request, int $user, string $token): RedirectResponse
    {
        /** @var User|null $target */
        $target = User::query()->find($user);

        if ($target === null || $target->pending_email === null || $target->pending_email_token === null
            || ! hash_equals($target->pending_email_token, hash('sha256', $token))) {
            return redirect()->route('login')->with('error', 'This link has already been used or replaced by a newer one.');
        }

        if (User::withTrashed()->where('email', $target->pending_email)->whereKeyNot($target->id)->exists()) {
            return redirect()->route('login')->with('error', 'That address now belongs to another login. Ask the Owner.');
        }

        $old = $target->email;
        $new = $target->pending_email;
        $by = User::query()->find($target->pending_email_by);

        DB::transaction(function () use ($target, $old, $new) {
            $target->forceFill(['email' => $new, 'pending_email' => null, 'pending_email_token' => null, 'pending_email_sent_at' => null, 'pending_email_by' => null])->save();
            DB::table('password_reset_tokens')->where('email', $old)->delete();
        });

        if ($by !== null) {
            CorrectionLog::event('fixed_details', $by, $target, ['reason' => 'Confirmed by the new address', 'before' => ['email' => $old], 'after' => ['email' => $new]]);
        }

        return redirect()->route('login')->with('status', "Your login is now {$new}. Sign in with it.");
    }

    public function destroyLogin(Request $request, User $user): RedirectResponse
    {
        $this->authorize('foundation.users.manage');
        $this->ensureMember($user);

        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['reason_text' => 'You cannot delete your own login.']);
        }

        if (($why = $user->whyNotDeactivatable()) !== null) {
            throw ValidationException::withMessages(['reason_text' => $why]);
        }

        DB::transaction(fn () => $user->recycle($request->user(), $this->actionReason($request)));

        return back()->with('success', "{$user->name}'s login moved to the Recycle bin. Its email stays reserved.");
    }

    private function ensureMember(User $user): void
    {
        abort_unless($user->businesses()->where('businesses.id', app(BusinessContext::class)->id())->exists(), 404);
    }
}
