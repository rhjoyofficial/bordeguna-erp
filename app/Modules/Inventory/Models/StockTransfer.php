<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The header record for a warehouse-to-warehouse transfer, tracking the
 * explicit in-transit state between dispatch (stock leaves the source) and
 * receipt (stock lands at the destination) — see DispatchStockTransfer /
 * ReceiveStockTransfer. The stock_movements rows sharing this record's
 * transfer_group_id are the actual ledger entries; this model is the
 * workflow status on top of them.
 */
class StockTransfer extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'transfer_group_id',
        'product_id',
        'source_warehouse_id',
        'target_warehouse_id',
        'quantity',
        'reason_id',
        'note',
        'status',
        'dispatched_by',
        'dispatched_at',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'quantity' => 'decimal:4',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_id', 'source_warehouse_id', 'target_warehouse_id', 'quantity', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isInTransit(): bool
    {
        return $this->status === TransferStatus::InTransit;
    }
}
