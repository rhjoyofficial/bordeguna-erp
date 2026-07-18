<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The approval queue for adjustment/transfer requests submitted by users
 * without direct-adjust permission. Approving one dispatches/applies the
 * actual stock change via Inventory\Actions — this model never touches
 * stock itself, it only tracks the domain fields of the request. The
 * approval lifecycle itself (status/requested_by/reviewed_by) lives on the
 * paired ApprovalRequest, see the Approvable concern.
 */
class StockRequest extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'type',
        'product_id',
        'warehouse_id',
        'target_warehouse_id',
        'direction',
        'quantity',
        'reason_id',
        'unit_cost',
        'note',
        'resulting_movement_id',
        'resulting_transfer_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockRequestType::class,
            'direction' => AdjustmentDirection::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'product_id', 'warehouse_id', 'target_warehouse_id', 'direction', 'quantity'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function resultingMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'resulting_movement_id');
    }

    public function resultingTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'resulting_transfer_id');
    }
}
