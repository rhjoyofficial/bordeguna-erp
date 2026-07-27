<?php

namespace App\Modules\Hrm\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A queued report has finished rendering (Correction C10e).
 *
 * **The file is not attached.** A report large enough to have been queued is
 * large enough to bounce off a mailbox size limit, and — more to the point — it
 * holds exactly the payroll or attendance data the C10d access gate exists to
 * control. Emailing it would carry that data outside the system to whatever
 * address the account happens to have. The mail carries a link; the link is
 * still behind the login and still scoped to the user's own folder.
 */
class ReportReadyMail extends Mailable
{
    public function __construct(
        public readonly string $reportName,
        public readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('Your report is ready — %s', $this->reportName));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'hrm.mail.report-ready',
            with: [
                'reportName' => $this->reportName,
                'url' => route('hrm.reports.download', ['file' => $this->filename]),
            ],
        );
    }
}
