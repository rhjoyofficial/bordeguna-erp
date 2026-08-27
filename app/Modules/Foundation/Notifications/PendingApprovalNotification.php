<?php

namespace App\Modules\Foundation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an approver that something is waiting in their queue.
 *
 * Email-only for now; the multi-channel `via()` shape is where SMS / WhatsApp /
 * push get added once a vendor is chosen (RPT-05).
 *
 * **Queued since 2026-07-29 (roadmap A3 / plan §7 W6).** Phase 0 shipped this
 * synchronously with a note that production needs a worker. Sending inline made
 * every approval submission wait on the mail server, so a slow or unreachable
 * SMTP host did not merely delay a notification — it delayed, and on timeout
 * failed, the user's request to submit the thing being approved.
 *
 * ⚠⚠ `$afterCommit` IS LOAD-BEARING, NOT A PRECAUTION. Twelve of the twenty
 * callers dispatch this from **inside a `DB::transaction`** (RequestLeave,
 * RequestLoan, SubmitStockRequest, CreatePurchaseOrder and the rest). Without
 * it, the queue worker can pick the job up before the transaction commits and
 * email an approver a link to a record that does not exist yet — or, if the
 * transaction rolls back, a record that never existed at all. Synchronous
 * sending could not produce that, so this race is introduced *by* queueing and
 * has to be closed in the same change.
 *
 * ⚠ Retries are deliberate here, unlike `RenderReportJob` which sets `tries=1`.
 * That job restores tenant context by hand and a blind retry is not obviously
 * safe; this one carries two plain strings, reads only the notifiable's name,
 * and touches no business-scoped data — so a retry is idempotent, and the
 * common failure (a momentarily unreachable SMTP host) is exactly what retries
 * exist for.
 *
 * ℹ A job that exhausts its retries lands in `failed_jobs`. Turning that into a
 * signal somebody actually sees is the rest of W6 (delivery tracking, "this
 * payslip email bounced") and is deliberately not attempted here.
 */
class PendingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds between attempts: give a flapping mail server room to recover. */
    public int $backoff = 30;

    public function __construct(
        public string $summary,
        public string $url,
    ) {
        // ⚠ Set through Queueable's own method rather than by redeclaring the
        // `$afterCommit` property — the trait already declares it, and a
        // redeclaration with a different type is a fatal composition error.
        //
        // This is the line that stops a worker emailing an approver about a
        // record that has not committed, or has been rolled back. See the class
        // docblock: twelve of the twenty callers dispatch inside a transaction.
        $this->afterCommit();
    }

    /**
     * ⚠⚠ IN-APP ONLY SINCE 2026-08-23, ON JOY'S INSTRUCTION: "do not send mail
     * to request correction, ERP notification is enough." Every leave request,
     * loan, correction, stock request and purchase order used to email its
     * approver against a live SMTP host. The bell in the header and the
     * approvals screen are where this belongs.
     *
     * ⚠ `toMail()` is KEPT below rather than deleted. The channel list is the
     * switch; the message is the thing that would have to be rewritten from
     * scratch if email is ever wanted again (a customer-facing deployment, an
     * approver who is not a daily user). Deleting a working mail template to
     * express a routing decision throws away the wrong half.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * What the bell shows.
     *
     * ⚠ The URL is stored rather than rebuilt on read, so a notification still
     * points where it pointed when it was raised, even if the route later moves.
     *
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approval needed: Bor de Guna ERP')
            ->greeting("Hello {$notifiable->name},")
            ->line('An item is waiting for your approval:')
            ->line($this->summary)
            ->action('Review approvals', $this->url)
            ->line('This is an automated message.');
    }
}
