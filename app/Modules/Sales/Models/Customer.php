<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Enums\CustomerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Who we sell to.
 *
 * ⚠ A walk-in customer is a REAL ROW, not a null customer_id. A null means every
 * report has to special-case it, and it makes a counter sale indistinguishable
 * from a data-entry mistake where somebody forgot to pick the customer.
 */
class Customer extends Model
{
    use BelongsToBusiness, LogsActivity;

    /** The code of the per-business walk-in row planted by WalkInCustomerSeeder. */
    public const WALK_IN_CODE = 'WALK-IN';

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'type',
        'contact_name',
        'phone',
        'email',
        'address',
        'payment_terms',
        'credit_limit',
        'price_group',
        'is_system',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'credit_limit' => 'decimal:4',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'phone', 'payment_terms', 'credit_limit', 'price_group', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The walk-in counter customer for the business in context.
     *
     * ⚠ Returns null rather than creating one. A seeder plants it; a getter that
     * creates master data on read is how a test passes against a row production
     * does not have.
     */
    public static function walkIn(): ?self
    {
        return static::query()->where('code', self::WALK_IN_CODE)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
