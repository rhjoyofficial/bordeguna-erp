<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A brand the business sells under: LaLaDia, Bionic-Garden, and whatever comes
 * next.
 *
 * ⚠⚠ A BRAND IS NOT A TENANT. LaLaDia and Bionic-Garden used to be separate
 * businesses in this system, holding four demo products each. They are now
 * brands inside Bor de Guna, which is what lets one catalogue, one set of FIFO
 * cost layers and one profit report cover all of them. Duplicating products
 * across businesses instead would have split stock and made per-product
 * profitability unanswerable.
 *
 * ⚠ Business-scoped, so `BusinessScope` fails closed on it exactly as it does
 * for categories and units. A product may only point at a brand in its own
 * business, which is asserted structurally rather than trusted.
 */
class Brand extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'slug',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * ⚠ A freshly created model does not carry its database defaults, so a
     * brand built in memory would report `is_active` as null until it was
     * reloaded. Declared here, as the standing rule requires.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
