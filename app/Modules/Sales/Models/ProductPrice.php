<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One pricing rule. See the migration for why the shape is what it is.
 */
class ProductPrice extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'product_id',
        'customer_id',
        'price_group_id',
        'min_quantity',
        'unit_price',
        // How the price was set (21 Sep 2026): 'fixed', or 'percent_off_mrp'
        // with the percentage and the MRP it was worked out from. ⚠ Record
        // only: unit_price is the price, and nothing recomputes it from these.
        'price_method',
        'percent_off',
        'basis_mrp',
        'effective_from',
        'effective_to',
        'is_discount',
        'discount_label',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'percent_off' => 'decimal:4',
            'basis_mrp' => 'decimal:4',
            // ⚠ Dates, not datetimes. Comparing a datetime cast against a date
            // string has produced five separate defects on this project.
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_discount' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class);
    }

    /**
     * Rules in force on `$on`.
     *
     * ⚠ Compared with whereDate against a date string, exactly as
     * AccountMapping::forKeyOn does. This is the fifth application of the same
     * lesson and it is deliberate that the two read identically.
     */
    public function scopeInForceOn(Builder $query, Carbon $on): Builder
    {
        $date = $on->toDateString();

        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * How specific this rule is. Higher wins.
     *
     * ⚠ The number is derived from the columns, never stored. A stored priority
     * is a second opinion about specificity that drifts from the row it
     * describes the first time somebody edits one and not the other.
     */
    public function specificity(): int
    {
        return match (true) {
            $this->customer_id !== null => 3,
            $this->price_group_id !== null => 2,
            default => 1,
        };
    }
}
