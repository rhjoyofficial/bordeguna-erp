<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee a show cause notice has been issued to them, with the link
 * to read and print their copy. In-app only - the notice itself is the formal
 * document; this is the bell that says it exists.
 */
class ShowCauseIssuedNotification extends Notification implements ShouldQueue
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
            'kind' => 'show_cause',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
