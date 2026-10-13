<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Goods that left on a challan and came back - anchored to the delivery, with
 * the money derived (SALES-RETURNS-ARCHITECTURE.md §2).
 *
 * ⚠ Append-only, like every document here: a return references the delivery
 * and its invoices, it never edits them. A wrong return is corrected by the
 * ordinary reversal doctrine, not by editing history.
 */
class SalesReturn extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'customer_id',
        'delivery_id',
        'return_number',
        'returned_at',
        'credit_total',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'returned_at' => 'date:Y-m-d',
            'credit_total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['return_number', 'customer_id', 'delivery_id', 'returned_at', 'credit_total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalesReturnAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class);
    }

    public function creditTotalMoney(): Money
    {
        return Money::ofTaka((string) $this->credit_total);
    }

    /**
     * What has already been physically paid back against this return's credit.
     * ⚠ Derived from the refund documents, never stored - the SupplierBill
     * reasoning, again.
     */
    public function refundedTotal(): Money
    {
        return Money::ofTaka((string) ($this->refunds()->sum('amount') ?: '0'));
    }

    /**
     * Credit already used to reduce an invoice's outstanding.
     */
    public function allocatedTotal(): Money
    {
        return Money::ofTaka((string) ($this->allocations()->sum('amount') ?: '0'));
    }

    /**
     * The cap on the next refund (C-SR-6): the net credit this return created,
     * less what has already gone back.
     *
     * ⚠⚠ Less what was ALLOCATED to invoices too. Credit that already reduced
     * an invoice is spent; paying it out again in cash would give the customer
     * the same taka twice and push the receivable negative.
     */
    public function refundable(): Money
    {
        return Money::ofMinor(max(0,
            $this->creditTotalMoney()->minor - $this->allocatedTotal()->minor - $this->refundedTotal()->minor,
        ));
    }
}
