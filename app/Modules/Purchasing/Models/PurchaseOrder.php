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
        // ⭐ D-G, 18 Oct 2026: the day the order was placed with the supplier,
        // often earlier than the day it was typed in (created_at).
        'order_date',
        'expected_date',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
        ];
    }

    /**
     * ⚠ 18 Oct 2026 (D-G): the column is NOT NULL, and an order written by any
     * path that does not name a day (a seeder, an import, an older caller) was
     * placed on the day it is written. CreatePurchaseOrder always names it.
     */
    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order) {
            if ($order->order_date === null) {
                $order->order_date = now()->toDateString();
            }
        });
    }

    /** The order date as Y-m-d (Dhaka calendar), or null on an unsaved row. */
    public function orderDateString(): ?string
    {
        return $this->order_date?->toDateString();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'order_date'])
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
