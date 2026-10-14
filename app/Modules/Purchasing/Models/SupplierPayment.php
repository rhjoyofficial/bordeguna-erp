<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Concerns\Reversible;
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
    // PUR-003 (14 Oct 2026): corrected by reversing it, never edited.
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'money_account_id',
        // The voucher that created this payment (finance decision C3), if any.
        'voucher_id',
        'paid_on',
        'amount',
        'tds_rate_id',
        'tds_amount',
        'vds_rate_id',
        'vds_amount',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'tds_amount' => 'decimal:4',
            'vds_amount' => 'decimal:4',
            // PUR-003: the bills it had settled, kept once it is reversed.
            'reversed_allocations' => 'array',
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

    /** What actually left the money account: the amount settled less the tax held back. */
    public function netPaid(): Money
    {
        return Money::ofMinor(
            Money::ofTaka((string) $this->amount)->minor
            - Money::ofTaka((string) $this->tds_amount)->minor
            - Money::ofTaka((string) $this->vds_amount)->minor
        );
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
