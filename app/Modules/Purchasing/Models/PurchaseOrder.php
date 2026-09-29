<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PaymentStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A PO is always Approvable (see Approvable concern) — Owner/Manager
 * self-approve immediately on creation (see CreatePurchaseOrder), Staff-
 * created POs sit pending until reviewed. Unlike StockRequest, approving a
 * PO has no domain side effect by itself — it only makes the PO eligible
 * for RecordGoodsReceipt.
 */
class PurchaseOrder extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'po_number',
        'supplier_id',
        'warehouse_id',
        'status',
        // ⚠⚠ RETIRED 21 Sep 2026, kept only so old rows still read. It was a
        // hand-set "Unpaid / Partially paid / Paid" dropdown that moved no money
        // and reached no ledger, so a PO could say "Paid" while Accounts Payable
        // said the supplier was owed in full. Nothing writes it any more; every
        // screen derives payment from the order's supplier bills instead.
        'payment_status',
        'expected_date',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'expected_date' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * The supplier's invoices raised against this order. Whether the order is
     * paid is read from THESE (their allocations and credit notes), never from
     * the old `payment_status` column, which nothing ever tied to money.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function isReceivable(): bool
    {
        return $this->approvalRequest->status === ApprovalStatus::Approved
            && in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::PartiallyReceived], true);
    }

    /**
     * True once every line's received quantity has been fully returned (or
     * nothing was ever received) — the gate CancelPurchaseOrder uses to allow
     * cancelling a partially/fully received order once it's genuinely being
     * abandoned rather than left in limbo.
     */
    public function hasNoOutstandingReceivedStock(): bool
    {
        return $this->lines->every(fn (PurchaseOrderLine $line) => (float) $line->quantityReturnable() <= 0.0);
    }
}
