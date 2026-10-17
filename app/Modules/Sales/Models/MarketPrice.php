<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Today's price of a market product, one row per product and channel.
 *
 * ⚠ Not a second pricing engine: the sale is still priced by
 * ResolveSellingPrice from the live product_prices row this points at. This
 * row is the badge ("Today's price, since 10:15"), the counter's one-query
 * lookup, the staff-purchase cap reference, and the DB-enforced "one active
 * price per product/business/channel" (ChatGPT ruling, Q4).
 */
class MarketPrice extends Model
{
    use BelongsToBusiness;

    public const CHANNEL_RETAIL = 'retail';

    protected $fillable = [
        'business_id',
        'product_id',
        'channel',
        'unit_price',
        'cost_snapshot',
        'product_price_id',
        'product_price_history_id',
        'confirmed_by',
        'since_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'cost_snapshot' => 'decimal:4',
            'since_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function history(): BelongsTo
    {
        return $this->belongsTo(ProductPriceHistory::class, 'product_price_history_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** "Since 10:15", in the app's Dhaka clock. */
    public function sinceLabel(): string
    {
        return $this->since_at->format('H:i');
    }
}
