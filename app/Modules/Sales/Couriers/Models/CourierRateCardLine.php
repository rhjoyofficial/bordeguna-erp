<?php

namespace App\Modules\Sales\Couriers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One price on a rate card: from band, to band, service, a weight bracket
 * (above `weight_from_g`, up to and including `weight_to_g`; null = no top),
 * an optional charge per started kilogram above a weight, and the scenario
 * figures (return fee, expected discount).
 *
 * ⚠ A line of an approved or retired card cannot be written or removed: the
 * card is a record of what was in force.
 */
class CourierRateCardLine extends Model
{
    public const SERVICES = ['normal', 'express', 'same_day'];

    protected $fillable = [
        'courier_rate_card_id', 'from_band', 'to_band', 'service', 'weight_from_g', 'weight_to_g', 'price',
        'extra_per_kg_after_g', 'extra_per_kg_price', 'return_fee_percent', 'expected_discount',
        'sample_count', 'previous_price',
    ];

    protected function casts(): array
    {
        return [
            'weight_from_g' => 'integer',
            'weight_to_g' => 'integer',
            'price' => 'decimal:2',
            'extra_per_kg_after_g' => 'integer',
            'extra_per_kg_price' => 'decimal:2',
            'return_fee_percent' => 'decimal:2',
            'expected_discount' => 'decimal:2',
            'sample_count' => 'integer',
            'previous_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = CourierRateCard::query()->whereKey($line->courier_rate_card_id)->value('status');

            if ($status !== null && $status !== CourierRateCard::DRAFT) {
                throw new LogicException('An approved rate card does not change. Make a new version.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CourierRateCard::class, 'courier_rate_card_id');
    }

    public function covers(int $grams): bool
    {
        return $grams > $this->weight_from_g && ($this->weight_to_g === null || $grams <= $this->weight_to_g);
    }

    /**
     * The price for a weight on this line before any expected discount: the
     * base price plus each started kilogram above the extra threshold.
     */
    public function priceFor(int $grams): float
    {
        $price = (float) $this->price;

        if ($this->extra_per_kg_after_g !== null && $this->extra_per_kg_price !== null && $grams > $this->extra_per_kg_after_g) {
            $price += (int) ceil(($grams - $this->extra_per_kg_after_g) / 1000) * (float) $this->extra_per_kg_price;
        }

        return round($price, 2);
    }

    /** True when the weight is inside the base price (no extra per kg added). */
    public function isBaseWeight(int $grams): bool
    {
        return $this->extra_per_kg_after_g === null || $grams <= $this->extra_per_kg_after_g;
    }

    public function bracketLabel(): string
    {
        return self::bracket($this->weight_from_g, $this->weight_to_g);
    }

    /** "0-500 g", "1000+ g": for screens and as the bracket key. */
    public static function bracket(int $from, ?int $to): string
    {
        return $to === null ? "{$from}+ g" : "{$from}-{$to} g";
    }
}
