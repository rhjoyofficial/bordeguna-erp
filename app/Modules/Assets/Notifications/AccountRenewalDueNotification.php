<?php

namespace App\Modules\Assets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An account the business owns is about to renew, or should have (slice 4,
 * Joy's answer 3: in-app only, at 30 days, 7 days and overdue, once each).
 *
 * ⚠ One per account per stage rather than a digest: three bell lines over a
 * month for a domain is the right weight, and each names the account so the
 * owner can act from the notification itself.
 */
class AccountRenewalDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $accountId,
        public string $name,
        public string $kindLabel,
        public string $renewsOn,
        public string $stage,
        public int $days,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'account_renewal_due',
            'summary' => $this->summary(),
            'url' => '/assets/accounts/'.$this->accountId,
            'account_id' => $this->accountId,
            'stage' => $this->stage,
            'renews_on' => $this->renewsOn,
        ];
    }

    public function summary(): string
    {
        $what = "{$this->name} ({$this->kindLabel})";

        return match ($this->stage) {
            'overdue' => "{$what} renewal is overdue: it was due {$this->renewsOn}, ".abs($this->days).' '.(abs($this->days) === 1 ? 'day' : 'days').' ago.',
            default => "{$what} renews on {$this->renewsOn}, in {$this->days} ".($this->days === 1 ? 'day' : 'days').'.',
        };
    }
}
