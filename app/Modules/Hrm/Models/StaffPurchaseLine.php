<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a staff purchase request. The staff price and its cost
 * basis are FROZEN at approval (Phase 2, ruling Q1) and the cap reference
 * price at request time (ruling Q3); neither is ever recomputed in place.
 */
class StaffPurchaseLine extends Model
{
    use BelongsToBusiness;

    /** The one unit per SKU a month, at the reserved batch cost. */
    public const PRICED_AT_STAFF = 'staff';

    /** A further unit in the same month, at the cap reference price (MRP or market). */
    public const PRICED_AT_REFERENCE = 'reference';

    /**
     * ⭐ Joy's answer 6 (25 Oct 2026): a not-for-sale item sold to an employee
     * at a price the Owner or a Manager typed, with the reason (custom_reason).
     * Outside the monthly allowance and the one-unit rule, which measure the
     * staff benefit, not this.
     */
    public const PRICED_AT_CUSTOM = 'custom';

    protected $fillable = [
        'business_id',
        'request_id',
        'product_id',
        'quantity',
        'staff_price',
        'mrp',
        'cost_basis_batch_id',
        'cost_snapshot',
        'cap_reference_price',
        'cap_reference_label',
        'priced_at',
        'custom_reason',
        'custom_by',
        'price_frozen_at',
    ];

    protected $attributes = [
        'quantity' => 1,
        'priced_at' => self::PRICED_AT_STAFF,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'staff_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'cap_reference_price' => 'decimal:2',
            'cost_snapshot' => 'array',
            'price_frozen_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffPurchaseRequest::class, 'request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function costBasisBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'cost_basis_batch_id');
    }

    public function isCustomPriced(): bool
    {
        return $this->priced_at === self::PRICED_AT_CUSTOM;
    }

    public function isAtStaffPrice(): bool
    {
        return $this->priced_at === self::PRICED_AT_STAFF;
    }

    public function isFrozen(): bool
    {
        return $this->price_frozen_at !== null;
    }
}
