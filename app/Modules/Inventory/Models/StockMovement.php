<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Immutable ledger entry: never updated after creation (see migration —
 * no updated_at column). Corrections are new movements that reference the
 * one they reverse via reverses_movement_id.
 */
class StockMovement extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'product_id',
        'warehouse_id',
        'batch_id',
        'direction',
        'reason',
        'reason_id',
        'quantity',
        'unit_cost',
        'transfer_group_id',
        'reverses_movement_id',
        'performed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MovementDirection::class,
            'reason' => MovementReason::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_id', 'warehouse_id', 'direction', 'reason', 'quantity'])
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }
}
