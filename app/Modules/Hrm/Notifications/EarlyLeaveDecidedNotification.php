<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee their early leave request was approved or rejected (Joy's
 * HRM queue, 27 Oct 2026). Without it a decision is silent, and the person
 * finds out from a half day on their payslip.
 *
 * ⚠ In-app only, like every approval message since 23 Aug 2026 ("ERP
 * notification is enough"). Queued after commit: the decision is written in
 * the same request, and a worker must never announce one that rolled back.
 */
class EarlyLeaveDecidedNotification extends Notification implements ShouldQueue
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
            'kind' => 'approval',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
