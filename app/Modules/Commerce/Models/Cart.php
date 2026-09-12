<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A basket somebody has started on a shop.
 *
 * ⭐⭐ IT RECORDS WHAT WAS CHOSEN, NEVER WHAT IT COSTS. Every figure is resolved
 * at read time by `CartPresenter` through `CataloguePublishing::priceFor`, the
 * one method the publish gate and the storefront already share. See the
 * migration for why, and for why this is a table rather than a session.
 *
 * ⚠ `converted_at` is deliberately NOT fillable. A basket becomes an order in
 * checkout (slice 2.4) through `markConverted()`, never by a form posting a
 * timestamp. Until then its absence IS the "open" state.
 */
class Cart extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'store_id',
        'token',
        'contact_name',
        'contact_phone',
        'contact_email',
        // ⚠⚠ THE DISTRICT, NEVER THE CHARGE. There is deliberately no delivery
        // charge column here, for the same reason there is no price on a line:
        // a stored charge goes stale the moment a rate is edited, and a basket
        // quoting a number the shop no longer charges is worse than no quote.
        // `DeliveryQuote` prices this at read time, every time.
        'delivery_district',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class);
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * The order this basket became, or null while it is still open.
     *
     * ⚠ Written in the same commit as the column that makes it possible, and it
     * is also the checkout's idempotency key: a second submit of the same
     * basket finds this order rather than writing a twin.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /**
     * ⚠ Takes the order rather than only stamping a time. "It converted" and
     * "it converted into nothing identifiable" are different facts, and the
     * second is what a recovery screen cannot act on.
     */
    public function markConverted(SalesOrder $order): void
    {
        $this->forceFill([
            'converted_at' => now(),
            'sales_order_id' => $order->getKey(),
        ])->save();
    }

    /**
     * ⚠⚠ RANDOM, AND LONG. The token is the whole of what a cookie proves. An
     * incrementing id would let anybody read the next person's basket by
     * subtracting one, and a basket carries a name and a phone number by the
     * time it matters.
     */
    public static function newToken(): string
    {
        return Str::random(48);
    }
}
