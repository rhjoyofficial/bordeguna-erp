<?php

namespace App\Modules\Hrm\Mail;

use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Payslip\PayslipPassword;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A payslip on its way to the employee (DIS-04).
 *
 * The PDF is attached already encrypted — the same protected file the portal
 * hands out, not a plainer one made for email, which would defeat SAL-07 at the
 * least private step of the whole journey.
 *
 * The body states the **rule** for the password and never the password itself.
 * An email that carries both the locked document and its key is an unlocked
 * document with extra steps.
 */
class PayslipMail extends Mailable
{
    public function __construct(
        public readonly Payslip $payslip,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Payslip — %s',
                $this->payslip->run?->period?->label() ?? 'your latest payroll',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'hrm.mail.payslip',
            with: [
                'employeeName' => $this->payslip->employee?->fullName(),
                'period' => $this->payslip->run?->period?->label(),
                'net' => number_format((float) $this->payslip->net, 2),
                'passwordHint' => PayslipPassword::HINT,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
