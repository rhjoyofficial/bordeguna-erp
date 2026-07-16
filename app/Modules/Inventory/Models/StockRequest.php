<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockRequestStatus;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The approval queue for adjustment/transfer requests submitted by users
 * without direct-adjust permission. Approving one creates the actual
 * StockMovement(s) via Inventory\Actions — this model never touches stock
 * itself, it only tracks the request lifecycle.
 */
class StockRequest extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'type',
        'product_id',
        'warehouse_id',
        'target_warehouse_id',
        'direction',
        'quantity',
        'reason_note',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'resulting_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockRequestType::class,
            'direction' => AdjustmentDirection::class,
            'status' => StockRequestStatus::class,
            'quantity' => 'decimal:4',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'product_id', 'warehouse_id', 'target_warehouse_id', 'direction', 'quantity', 'status'])
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resultingMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'resulting_movement_id');
    }
}
