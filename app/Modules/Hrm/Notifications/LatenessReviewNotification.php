<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells HR that an employee has used the last FREE occurrence of a lateness
 * window this month - the next one carries the ladder's consequence, so this
 * is the moment to review and consider a show cause notice (Joy, 24 Aug 2026).
 *
 * In-app only, like every notification since 23 Aug: mail is off by policy.
 * Queued with afterCommit for the same reason PendingApprovalNotification is -
 * the alert row it accompanies may be written inside a transaction, and a
 * worker must never announce a row that rolled back.
 */
class LatenessReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $summary,
        public string $url,
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
            'kind' => 'lateness_review',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
