<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The attendance reminders (Joy's HRM queue, 27 Oct 2026): an early leave
 * request that has waited too long, and the days still provisional a few days
 * before the payroll cycle ends. One digest per person per run.
 *
 * ⚠ In-app only, like every HR notification since 23 Aug 2026. `kind` is
 * `lateness` so the bell draws the alarm clock attendance alerts already use.
 */
class AttendanceReminderNotification extends Notification implements ShouldQueue
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
            'kind' => 'lateness',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
