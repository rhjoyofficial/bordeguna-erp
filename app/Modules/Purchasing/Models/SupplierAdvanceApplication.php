<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An advance used against a bill (ruling P2): Dr 2110 Accounts Payable / Cr
 * 1160 Advances to Suppliers, both with the supplier as party.
 *
 * ⚠ One advance, one bill, one row. Several advances on one bill are several
 * rows, so "which advance paid this bill" is always one lookup.
 *
 * ⚠ `supplier_advance_id` is NULL for money drawn from the supplier's
 * ON-ACCOUNT 1160 balance: the part of 1160 that Payment vouchers and journals
 * put there without an advance document (see SupplierAdvanceBook).
 *
 * ⚠ The bill is reduced through `party_adjustment_id`, an `advance_applied`
 * adjustment, so every figure that already subtracts discounts and set-offs
 * from a bill subtracts this too. It is removed when this is reversed.
 */
class SupplierAdvanceApplication extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'supplier_id',
        'supplier_advance_id',
        'supplier_bill_id',
        'applied_on',
        'amount',
        'tds_covered',
        'party_adjustment_id',
        'source_note',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'applied_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'tds_covered' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'supplier_id', 'supplier_advance_id', 'supplier_bill_id', 'applied_on', 'amount'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class, 'party_adjustment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function isOnAccount(): bool
    {
        return $this->supplier_advance_id === null;
    }
}
