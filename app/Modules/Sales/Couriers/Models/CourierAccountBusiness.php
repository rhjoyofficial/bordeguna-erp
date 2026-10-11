<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A business's use of a courier account: from which pickup store, and into
 * which courier money account (1145) the cash on delivery is owed.
 *
 * ⚠ This row, not the account, holds the store and the money mapping (ChatGPT
 * ruling 11), because one account serves two businesses that ship from
 * different stores and keep separate books.
 *
 * ⭐ Joy, 11 Oct 2026: A LINK SERVES A BRAND. `brand_id` says which brand's
 * orders go through it; null = any brand. One account may be linked to one
 * business once per brand, and once for any brand (the writer keeps that one
 * unique: MySQL lets NULLs repeat in a unique index). The default is one per
 * (business, courier, brand), any-brand being its own bucket.
 *
 * ⚠ Business-scoped by the global scope. A raw query on this table must still
 * filter `business_id` by hand.
 */
class CourierAccountBusiness extends Model
{
    use BelongsToBusiness;

    protected $table = 'courier_account_business';

    protected $fillable = [
        'courier_account_id', 'business_id', 'brand_id', 'provider_store_id', 'provider_store_name',
        'money_account_id', 'is_default', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Of one account's links in one business, the one that serves an order of
     * this brand: its own brand's link first, then the any-brand link. An
     * order with no brand takes the any-brand link, else the only link.
     *
     * @param  Collection<int, self>  $links
     */
    public static function serving(Collection $links, ?int $brandId): ?self
    {
        $any = $links->first(fn (self $link) => $link->brand_id === null);

        if ($brandId !== null) {
            return $links->first(fn (self $link) => $link->brand_id !== null && (int) $link->brand_id === $brandId) ?? $any;
        }

        return $any ?? ($links->count() === 1 ? $links->first() : null);
    }
}
