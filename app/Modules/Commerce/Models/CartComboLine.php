<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bundle somebody put in a basket, and how many of it.
 *
 * ⛔ No price, for the reason `cart_lines` carries none: what a bundle costs is
 * resolved every time the basket is read, by `ComboPricing`.
 */
class CartComboLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'cart_id',
        'store_combo_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(StoreCombo::class, 'store_combo_id');
    }
}
