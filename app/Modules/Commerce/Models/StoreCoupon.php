<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A coupon one shop offers (slice 2.5b).
 *
 * ⛔ No usage counter. Usage is counted from sales orders, cancelled ones
 * excluded - see `CouponPricing::timesUsed()` and the migration.
 */
class StoreCoupon extends Model
{
    use BelongsToBusiness;
    use LogsActivity;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const APPLIES_ALL = 'all';

    public const APPLIES_PRODUCTS = 'products';

    public const APPLIES_COMBOS = 'combos';

    protected $fillable = [
        'store_id',
        'code',
        'type',
        'value',
        'min_purchase',
        'usage_limit',
        'limit_per_customer',
        'starts_at',
        'ends_at',
        'is_active',
        'is_public',
        'applies_to',
        'is_free_delivery',
        'gift_product_id',
        'gift_quantity',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'usage_limit' => 'integer',
            'limit_per_customer' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_free_delivery' => 'boolean',
            'gift_quantity' => 'integer',
        ];
    }

    /**
     * ⚠ One spelling for a code everywhere it is stored or looked up.
     */
    public static function normaliseCode(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code) ?? '');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'store_coupon_products', 'store_coupon_id', 'product_id');
    }

    public function combos(): BelongsToMany
    {
        return $this->belongsToMany(StoreCombo::class, 'store_coupon_combos', 'store_coupon_id', 'store_combo_id');
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    /**
     * ⚠ Every change is logged: a coupon is money a customer does not pay, and
     * "who made it 50%, and when" is a question somebody will ask.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'type', 'value', 'min_purchase', 'usage_limit', 'limit_per_customer', 'starts_at', 'ends_at', 'is_active', 'is_public', 'applies_to', 'is_free_delivery', 'gift_product_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
