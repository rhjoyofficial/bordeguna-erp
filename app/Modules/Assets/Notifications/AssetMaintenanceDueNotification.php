<?php

namespace App\Modules\Assets\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One message a day to whoever keeps the register (slice A4): plans due or
 * overdue, faults still open, warranties and service contracts about to end.
 *
 * ⚠ A DIGEST, NOT ONE PER THING, the house rule for reminders. Overdue is said
 * separately, because "1 already late" is what changes the morning.
 */
class AssetMaintenanceDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<string>  $named  up to a few "BDG-PRN-0001 Clean the head (due 2026-09-20)" lines
     */
    public function __construct(
        public int $due,
        public int $overdue,
        public int $open,
        public int $ending,
        public array $named,
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
            'kind' => 'asset_maintenance_due',
            'summary' => $this->summary(),
            'url' => '/assets/maintenance',
            'due' => $this->due,
            'overdue' => $this->overdue,
            'open' => $this->open,
            'ending' => $this->ending,
        ];
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->due > 0) {
            $parts[] = $this->due.' maintenance '.($this->due === 1 ? 'job' : 'jobs').' due'
                .($this->overdue > 0 ? " ({$this->overdue} already overdue)" : '');
        }

        if ($this->open > 0) {
            $parts[] = $this->open.' open '.($this->open === 1 ? 'fault or job' : 'faults or jobs');
        }

        if ($this->ending > 0) {
            $parts[] = $this->ending.' '.($this->ending === 1 ? 'warranty or service contract' : 'warranties or service contracts').' ending within 30 days';
        }

        $summary = ucfirst(implode(', ', $parts));

        return $this->named === [] ? $summary : $summary.': '.implode('; ', $this->named);
    }
}
