<?php

namespace App\Modules\Foundation\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The morning briefing (Module 7, Slice 2): yesterday and today on one
 * card, from the same arithmetic the screens use. It states figures; it
 * never advises.
 */
class MorningBriefingMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{date: string, yesterday_sales: float, cash_now: float, due_this_week: array<int, array{name: string, due_on: string, amount: float}>, tickets_overdue: ?int}  $figures
     */
    public function __construct(
        public readonly array $figures,
        public readonly string $businessName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->businessName.' - morning briefing, '.$this->figures['date'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'foundation.mail.morning-briefing',
            with: [
                'figures' => $this->figures,
                'businessName' => $this->businessName,
            ],
        );
    }
}
