<?php

namespace App\Modules\Sales\Enums;

/**
 * How a difference between what a parcel owed and what the courier collected
 * is settled (ChatGPT ruling 3, 11 Oct 2026: not every difference is a
 * discount). Chosen by a person, with a reason, on the outcome form.
 */
enum DoorResolution: string
{
    // Agreed off the price at the door: within the confirming person's
    // discount cap, or approved by the Owner.
    case Discount = 'discount';
    // The invoice price was wrong: the lines are billed at the corrected price.
    case PriceCorrection = 'price_correction';
    // The customer paid part and still owes the rest.
    case ShortCollection = 'short_collection';
    // Below Tk 1, usually cash on delivery rounded to whole taka.
    case Rounding = 'rounding';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Discount at the door',
            self::PriceCorrection => 'Price correction',
            self::ShortCollection => 'Short collection (customer owes the rest)',
            self::Rounding => 'Rounding (under Tk 1)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
