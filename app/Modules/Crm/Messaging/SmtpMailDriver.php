<?php

namespace App\Modules\Crm\Messaging;

use App\Modules\Crm\Models\MessageProvider;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email through the mailer the application is already configured with
 * (Joy, 29 Aug 2026: "email SMTP").
 *
 * ⚠⚠ NO SEPARATE MAIL CONNECTION. Laravel's mailer is configured once, in
 * `config/mail.php` from the environment, and this uses it. A second SMTP
 * configuration living in a database row would mean password reset mail and
 * campaign mail could silently disagree about who the business is.
 *
 * ⚠⚠ `MAIL_MAILER` IS `log` BY DEFAULT AND IS STILL `log` ON PRODUCTION. Nothing
 * leaves the server until the MAIL_* environment values are set. That is not a
 * bug in this class: mail is accepted, written to the log, and reported as
 * accepted, because from here it genuinely was. Joy sets the credentials.
 */
class SmtpMailDriver implements MessageDriver
{
    public function label(): string
    {
        return 'Email (the application mailer)';
    }

    public function send(MessageProvider $provider, string $to, string $body, ?string $subject = null): SendResult
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject, $provider) {
                $message->to($to)->subject($subject ?: 'A message from '.config('app.name'));

                // ⚠ Only when the provider names one. Otherwise the mailer's own
                // configured from-address is used, which is the one the rest of
                // the system already sends as.
                if ($provider->sender_id) {
                    $message->from($provider->sender_id, config('app.name'));
                }
            });

            return SendResult::accepted();
        } catch (Throwable $e) {
            // ⚠ One bad address must not abort a batch of four hundred.
            return SendResult::failed($e->getMessage());
        }
    }
}
