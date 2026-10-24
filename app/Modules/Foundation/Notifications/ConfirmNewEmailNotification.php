<?php

namespace App\Modules\Foundation\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): sent to the NEW address when the
 * Owner changes the email of a login that has signed in (FINAL-PLAN 8.1, 21:
 * "the new address is verified before any reset link is sent"). Nothing
 * changes until the person clicks.
 *
 * ⚠ Sent at once, never queued, like SetPasswordNotification: the Team screen
 * says whether it left.
 */
class ConfirmNewEmailNotification extends Notification
{
    public function __construct(private readonly string $name, private readonly string $url, private readonly string $until) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new email for the Bor de Guna ERP')
            ->greeting("Hello {$this->name},")
            ->line('Your login on the Bor de Guna ERP is moving to this email address. Confirm it with the button below.')
            ->action('Confirm this address', $this->url)
            ->line("This link works until {$this->until} (Dhaka time).")
            ->line('If you did not expect this email, ignore it. Nothing changes until the link is used.');
    }
}
