<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A pricing tier customers belong to: RETAIL, DEALER, DISTRIBUTOR, CORPORATE.
 *
 * ⚠⚠ A GROUP IS NOT A CUSTOMER TYPE, by Joy's explicit instruction
 * (2026-08-10): a customer's type never permanently determines their pricing
 * group, and a sales channel (Meta, online shop, counter, field sales) is
 * neither. Three concepts, three columns, and the day one is derived from
 * another is the day a report is silently wrong for the customers where they
 * differ.
 *
 * ⚠ RETAIL is the DEFAULT group: a customer with `price_group_id = null` is
 * priced as RETAIL. That mapping lives in ONE place, the selling-price
 * resolver, so there are never two opinions about what "no group" means.
 */
class PriceGroup extends Model implements Removable
{
    use BelongsToBusiness, LogsActivity, RecyclableMaster;

    /** The code of the group a customer with no group belongs to. D-PRC-2. */
    public const DEFAULT_CODE = 'RETAIL';

    /** Retail shops: below MRP, never MRP (26 Sep 2026). */
    public const SHOP_CODE = 'SHOP';

    /** The proprietor buying for himself at cost (D-B, 18 Oct 2026). */
    public const OWNER_CODE = 'OWNER';

    public const BASIS_LIST = 'list';

    public const BASIS_COST = 'cost';

    public function isCostBased(): bool
    {
        return $this->price_basis === self::BASIS_COST;
    }

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'prices_include_tax',
        // ⚠ Pays MRP when a product has no price for this group (21 Sep 2026).
        // True for RETAIL; a dealer group left false is REFUSED instead, which
        // is the owner's rule: no sale until he sets the price.
        'falls_back_to_mrp',
        // ⭐ D-B (18 Oct 2026): 'list' (rows, MRP fallback where allowed) or
        // 'cost' (the FIFO batch cost the sale consumes; the OWNER group).
        'price_basis',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
            'falls_back_to_mrp' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * ⚠ Database defaults repeated on the model, per the standing rule: a
     * freshly created instance must not report null for a column the database
     * would have defaulted.
     */
    protected $attributes = [
        'prices_include_tax' => true,
        'falls_back_to_mrp' => false,
        'price_basis' => self::BASIS_LIST,
        'is_active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'prices_include_tax', 'falls_back_to_mrp', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['price_group_id'];
    }

    public function binLabel(): string
    {
        return 'Price group '.$this->name;
    }

    public function whyKept(): ?string
    {
        return $this->code === self::DEFAULT_CODE ? 'The retail price group is part of the system: every customer without a group is priced in it.' : null;
    }

    public function whyNotDeactivatable(): ?string
    {
        return $this->code === self::DEFAULT_CODE ? 'The retail price group cannot be switched off, or the counter would have no prices.' : null;
    }
}
