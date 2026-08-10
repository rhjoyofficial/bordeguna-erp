<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
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
class PriceGroup extends Model
{
    use BelongsToBusiness, LogsActivity;

    /** The code of the group a customer with no group belongs to. D-PRC-2. */
    public const DEFAULT_CODE = 'RETAIL';

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'prices_include_tax',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
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
        'is_active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'prices_include_tax', 'is_active'])
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
}
