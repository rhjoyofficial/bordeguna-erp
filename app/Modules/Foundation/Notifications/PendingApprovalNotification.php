<?php

namespace App\Modules\Foundation\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an approver that something is waiting in their queue. Email-only for
 * now; the multi-channel via() shape is where SMS / WhatsApp / push get added
 * once a vendor is chosen (RPT-05). Sent synchronously today — production
 * should queue it (notification reliability, plan §7 W6).
 */
class PendingApprovalNotification extends Notification
{
    public function __construct(
        public string $summary,
        public string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approval needed: Bor De Guna ERP')
            ->greeting("Hello {$notifiable->name},")
            ->line('An item is waiting for your approval:')
            ->line($this->summary)
            ->action('Review approvals', $this->url)
            ->line('This is an automated message.');
    }
}
