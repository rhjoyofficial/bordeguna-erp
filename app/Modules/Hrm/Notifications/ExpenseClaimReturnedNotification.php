<?php

namespace App\Modules\Hrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee their expense claim came back for correction, and why.
 *
 * ⚠⚠ WITHOUT THIS THE FEATURE DOES NOT EXIST. A claim sent back is a claim
 * sitting silently in somebody's drafts: they submitted it, heard nothing, and
 * have no reason to look again. The bell is the whole difference between "sent
 * back for correction" and "disappeared".
 *
 * ⚠ In-app only, like every other approval message since 23 Aug 2026 on Joy's
 * instruction ("do not send mail to request correction, ERP notification is
 * enough").
 *
 * ⚠ `kind` is `expense` so the bell draws the receipt icon the expense
 * notifications already use, rather than falling back to a generic one.
 */
class ExpenseClaimReturnedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $summary,
        public string $url,
    ) {
        // The send-back runs beside a write to the claim; a worker that picked
        // this up first would tell somebody about a state that had not
        // committed.
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
            'kind' => 'expense',
            'summary' => $this->summary,
            'url' => $this->url,
        ];
    }
}
