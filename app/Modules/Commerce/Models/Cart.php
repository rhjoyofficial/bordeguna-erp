<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
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

    public function markConverted(): void
    {
        $this->forceFill(['converted_at' => now()])->save();
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
