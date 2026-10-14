<?php

namespace App\Modules\Crm\Enums;

/**
 * ⚠⚠ Adding a case shatters every exhaustive `match` over this enum. Grep first.
 *
 * ⚠ `Sending` is a real state, not a moment. A batch of five hundred takes time
 * on the queue, and without this a half-sent campaign would read as either not
 * started or finished, both of which invite somebody to press send again.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sending = 'sending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    // CRM-004 (audit 14 Oct 2026): a send that died part-way and that
    // somebody closed by hand, with a reason. Final, like Sent: what went out
    // is in the delivery rows, and the rest is never sent from this record.
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Waiting for approval',
            self::Approved => 'Approved, not sent',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Cancelled => 'Cancelled',
            self::Stopped => 'Stopped part-way',
        };
    }

    /** Whether the campaign may still be edited. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
