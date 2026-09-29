<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money actually leaving the business for a supplier.
 *
 * ⚠ The document comes first and the journal follows it. A payment is a thing
 * that happened - somebody moved money on a date from an account with a
 * reference - and the accounting entry is a consequence, not the point.
 */
class SupplierPayment extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'money_account_id',
        // The voucher that created this payment (finance decision C3), if any.
        'voucher_id',
        'paid_on',
        'amount',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'money_account_id', 'paid_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * ⚠ Derived, never stored. An "unallocated" column would be a second figure
     * that drifts the first time an allocation is added, and the allocations
     * themselves are the record of what this payment settled.
     */
    public function allocatedTotal(): string
    {
        return (string) $this->allocations()->sum('amount');
    }
}
