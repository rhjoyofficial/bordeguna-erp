<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Emails someone a link where they choose their own password.
 *
 * It is the ordinary password-reset token, used as an invitation: the person
 * choosing the password is the only one who ever knows it, so no admin ever
 * has to type a password on someone else's behalf.
 *
 * Returns the broker's status string, or MAIL_FAILED when the mail server
 * refused the message. Callers word their own message: a blank 500 tells the
 * admin nothing about whether the mail left the building.
 */
class SendPasswordSetupLink
{
    public const MAIL_FAILED = 'passwords.mail_failed';

    public function handle(User $user): string
    {
        try {
            return Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $e) {
            report($e);

            return self::MAIL_FAILED;
        }
    }

    /**
     * How long a link stays usable, in minutes. Read from config so the
     * message on screen can never drift from the actual expiry.
     */
    public function lifetimeMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
