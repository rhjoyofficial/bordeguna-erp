<?php

namespace App\Modules\Foundation\Notifications;

use App\Modules\Foundation\Actions\SendPasswordSetupLink;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email that lets somebody choose their own password: an invitation to a
 * new account and a forgotten-password reset alike (21 Sep 2026).
 *
 * ⚠ Sent at once, never queued: the Team screen reports whether it left, and a
 * queued mail would be reported as sent before anybody tried.
 *
 * ⚠ The lifetime is the configured one, said the way a person says it, with the
 * moment it ends, so an outside accountant knows by when to act.
 */
class SetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $setup = app(SendPasswordSetupLink::class);
        $until = now()->addMinutes($setup->lifetimeMinutes())->format('j M Y, g:i A');

        return (new MailMessage)
            ->subject('Set your password for the Bor de Guna ERP')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('You have an account on the Bor de Guna ERP (erp.bordeguna.com). Choose your own password with the button below. Nobody else will know it.')
            ->action('Choose my password', $this->resetUrl($notifiable))
            ->line("This link works for {$setup->lifetimeLabel()}, until {$until} (Dhaka time). After that, ask for a new one.")
            ->line('Your login is this email address: '.$notifiable->getEmailForPasswordReset().'.')
            ->line('If you did not expect this email, ignore it. Nothing changes until the link is used.');
    }
}
