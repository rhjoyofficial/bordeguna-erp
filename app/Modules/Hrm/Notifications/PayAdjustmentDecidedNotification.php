<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee that a payroll adjustment on HIS pay was approved, or
 * cancelled after it was (Joy, 27 Oct 2026: "does Nazmul can see why his
 * salary deduct"). Without it a penalty is silent until the payslip, and the
 * payslip is visible only once that payroll is finished.
 *
 * ⚠ In-app only, like every approval message since 23 Aug 2026. Queued after
 * commit: the decision is written in the same request, and a worker must never
 * announce one that rolled back.
 */
class PayAdjustmentDecidedNotification extends Notification implements ShouldQueue
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
            'kind' => 'pay',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
