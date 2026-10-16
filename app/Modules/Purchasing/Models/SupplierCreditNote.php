<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Concerns\Reversible;
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
    // PUR-003 (14 Oct 2026): corrected by reversing it, never edited.
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'purchase_return_id',
        'credit_note_number',
        'credit_date',
        'total',
        'tax_total',
        'note',
        'created_by',
    ];

    /**
     * ⚠ Bug hunt 16 Oct 2026 (the PUR-004 rule): `active_number_key` is the
     * credit note number while it is live and NULL once it is reversed, so the
     * database refuses a second LIVE note with one supplier number but lets the
     * corrected note reuse a reversed one's. Set on every save.
     */
    protected static function booted(): void
    {
        static::saving(function (self $creditNote): void {
            $creditNote->active_number_key = $creditNote->reversed_at === null ? $creditNote->credit_note_number : null;
        });
    }

    protected function casts(): array
    {
        return [
            'credit_date' => 'date:Y-m-d',
            'total' => 'decimal:4',
            // PUR-003: the bills it had settled, kept once it is reversed.
            'reversed_allocations' => 'array',
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
