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

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Waiting for approval',
            self::Approved => 'Approved, not sent',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Cancelled => 'Cancelled',
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
