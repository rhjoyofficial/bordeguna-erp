<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A supplier's own invoice - the Accounts Payable document.
 *
 * ⚠ Its number and its dates belong to the supplier. Ours are `id` and
 * `created_at`, and keeping both is what lets a bill arriving in August belong
 * to July.
 */
class SupplierBill extends Model
{
    use BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'purchase_order_id',
        'bill_number',
        'bill_date',
        'due_date',
        'total',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'purchase_order_id', 'bill_number', 'bill_date', 'due_date', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteAllocation::class);
    }

    /**
     * ⚠ DERIVED from the allocations, never stored on the bill.
     *
     * An `amount_paid` column would be a second source of truth for a figure the
     * allocations already answer, and it would drift the first time a payment
     * was recorded by a path that forgot to update it. This project spent four
     * Inventory slices removing exactly that shape from stock quantities.
     */
    public function paidTotal(): Money
    {
        return Money::ofTaka((string) ($this->allocations()->sum('amount') ?: '0'));
    }

    /**
     * What the supplier's credit notes have knocked off this bill.
     */
    public function creditedTotal(): Money
    {
        return Money::ofTaka((string) ($this->creditAllocations()->sum('amount') ?: '0'));
    }

    public function outstanding(): Money
    {
        return Money::ofTaka((string) $this->total)
            ->plus($this->paidTotal()->negated())
            ->plus($this->creditedTotal()->negated());
    }

    public function isSettled(): bool
    {
        return $this->outstanding()->minor <= 0;
    }
}
