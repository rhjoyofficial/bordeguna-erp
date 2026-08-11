<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A recorded promise: this customer has agreed to take these quantities of
 * these products at these prices.
 *
 * ⚠⚠ IT MOVES NO STOCK AND POSTS NO JOURNAL, EVER. Accounting recognises
 * events - goods leaving, revenue earned, money arriving - and a promise is
 * none of those. See SALES-ORDER-ARCHITECTURE.md §2, §7.
 *
 * ⚠ Always Approvable, mirroring PurchaseOrder (C-SO-1, Joy 2026-08-11):
 * Owner/Manager self-approve on creation, Staff-created orders sit pending
 * until reviewed, and goods may only leave against an APPROVED order.
 * Approving has no domain side effect by itself - it only makes the order
 * eligible for delivery.
 *
 * ⚠ The order is NOT required. The counter sale skips it, and a delivery with
 * no order behind it stays legal (the direct-delivery path). The "one engine"
 * rule of Phase 1, extended one document to the left.
 */
class SalesOrder extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'customer_id',
        'warehouse_id',
        'order_number',
        'ordered_at',
        'expected_date',
        'status',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'ordered_at' => 'date',
            'expected_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'order_number', 'customer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return $this->hasMany(SalesOrderLine::class);
    }

    /**
     * May goods still leave against this order? Mirrors PO::isReceivable():
     * approved, and not yet fully delivered, closed or cancelled.
     */
    public function isDeliverable(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved
            && in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered], true);
    }
}
