<?php

namespace App\Modules\Accounting\Mail;

use App\Modules\Sales\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The payment reminder a human chose to send (Module 2, Slice 5).
 *
 * Plain, factual, polite: the open invoices, their ages, the total, and who
 * to contact. No threats and no automation - the letter reads the way the
 * shop would say it across the counter.
 */
class PaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{number: string, date: string, due: ?string, days_overdue: int, outstanding: float}>  $invoices
     */
    public function __construct(
        public readonly Customer $customer,
        public readonly array $invoices,
        public readonly float $total,
        public readonly string $businessName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment reminder from '.$this->businessName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'accounting.mail.payment-reminder',
            with: [
                'customer' => $this->customer,
                'invoices' => $this->invoices,
                'total' => $this->total,
                'businessName' => $this->businessName,
            ],
        );
    }
}
