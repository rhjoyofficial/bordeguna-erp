<?php

namespace App\Modules\Assets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee a thing is now theirs (review 13 October 2026, ruling 9):
 * handed from the store or passed on by a colleague, either way it sits in
 * My assets waiting for their "yes, I have it". In-app only - the movement
 * is the document; this is the bell that says it exists.
 *
 * ⚠ A keeper's record of the hand-over does not mean the receiver knows. Nor
 * does the receiver's silence mean they have it: the acknowledgement is the
 * receiver's own step, and the bell is what makes them take it.
 */
class AssetHandedOverNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  string  $things  "BDG-IT-0001 ThinkPad" or "BDG-IT-0001 ThinkPad and 2 more"
     */
    public function __construct(
        public string $things,
        public string $number,
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
            'kind' => 'asset_handed_over',
            'summary' => "You now hold {$this->things}; please acknowledge in My assets ({$this->number}).",
            'url' => '/hrm/my-assets',
        ];
    }
}
