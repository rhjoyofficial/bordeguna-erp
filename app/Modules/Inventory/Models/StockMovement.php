<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
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
        'value_delta',
        'transfer_group_id',
        'production_run_id',
        'reverses_movement_id',
        'performed_by',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MovementDirection::class,
            'reason' => MovementReason::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value_delta' => 'decimal:4',
            // ⚠ When it happened in the warehouse, which is not `created_at`
            // (when we were told). Both are kept on purpose: an audit needs to
            // answer "what did we believe then" as well as "what was true then".
            'occurred_at' => 'datetime',
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
