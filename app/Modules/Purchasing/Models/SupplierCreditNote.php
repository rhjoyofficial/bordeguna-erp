<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A credit the supplier has issued for goods we returned after being billed.
 *
 * ⚠ Return-linked ONLY (D-SCN-1): the purchase return is the business event
 * this credit settles, and the cap on `total` is enforced by the recording
 * action against that return's billed value. A credit with no goods movement
 * behind it is a different event that does not exist here yet.
 */
class SupplierCreditNote extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'purchase_return_id',
        'credit_note_number',
        'credit_date',
        'total',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'total' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'credit_note_number', 'credit_date', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
