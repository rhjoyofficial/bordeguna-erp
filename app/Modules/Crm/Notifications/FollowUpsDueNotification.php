<?php

namespace App\Modules\Crm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One message a day to a person who owes somebody a follow-up.
 *
 * ⚠⚠ A DIGEST, NOT ONE PER TASK. Ten reminders in a morning are ten things to
 * dismiss, and the eleventh gets dismissed with them. One line that says how
 * many are due and how many are late, with the oldest named, is a message
 * somebody reads.
 *
 * ⚠ In-app. Mail now works and payslips go out by email, but a follow-up is
 * internal chivvying, not a document, and it belongs where the rest of the
 * system's alerts already are.
 */
class FollowUpsDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<string>  $titles
     */
    public function __construct(
        public int $dueToday,
        public int $overdue,
        public array $titles,
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
            'kind' => 'crm_follow_ups_due',
            'summary' => $this->summary(),
            'url' => '/crm/queue',
            'due_today' => $this->dueToday,
            'overdue' => $this->overdue,
            'titles' => $this->titles,
        ];
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->dueToday > 0) {
            $parts[] = $this->dueToday.' follow-up'.($this->dueToday === 1 ? '' : 's').' due today';
        }

        if ($this->overdue > 0) {
            // ⚠ Named as late rather than folded into the total. "3 due" reads
            // as today's work; "2 of them late" is the sentence that changes
            // what somebody does next.
            $parts[] = $this->overdue.' still open from before';
        }

        $summary = implode(', ', $parts);

        return $this->titles === []
            ? $summary
            : $summary.': '.implode('; ', $this->titles);
    }
}
