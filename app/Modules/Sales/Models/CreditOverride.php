<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One time an Owner approved an order the credit rules would have refused
 * (21 Sep 2026). ⚠ Written once and never changed or removed: it is the trail
 * an accountant audits, and a trail that can be edited is not one.
 */
class CreditOverride extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'customer_id',
        'sales_order_id',
        'rule',
        'credit_limit',
        'owed_before',
        'amount',
        'owed_after',
        'reason',
        'overridden_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'owed_before' => 'decimal:2',
            'amount' => 'decimal:2',
            'owed_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A credit override is a record of what happened and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A credit override is a record of what happened and cannot be removed.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
