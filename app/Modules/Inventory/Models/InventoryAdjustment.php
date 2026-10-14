<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Support\AdjustmentCause;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One inventory quantity or value correction, of any kind.
 *
 * ⚠⚠ Every correction is one of these, including auto-approved ones. There is no
 * direct path that skips the record - that was the divergence this slice exists
 * to remove, and it existed before any new adjustment type was added.
 *
 * The approval lifecycle lives on the paired `ApprovalRequest` (Approvable), the
 * decision trail on the activity log, the optional photo or document on
 * `HasAttachments`, and the resulting stock movements point back here.
 *
 * See INVENTORY-ADJUSTMENT-WORKFLOW-ARCHITECTURE.md.
 */
class InventoryAdjustment extends Model
{
    use Approvable, BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'type',
        'direction',
        'product_id',
        'warehouse_id',
        'stock_batch_id',
        'quantity',
        'reason_id',
        'unit_cost',
        'new_unit_cost',
        'effective_date',
        'estimated_value',
        'note',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryAdjustmentType::class,
            'direction' => AdjustmentDirection::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'new_unit_cost' => 'decimal:4',
            'estimated_value' => 'decimal:4',
            'effective_date' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'direction', 'product_id', 'warehouse_id', 'quantity', 'unit_cost', 'effective_date', 'estimated_value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * ⚠⚠ The discriminator is the DATA, not the type. An adjustment either moves
     * units (quantity > 0) or moves money (quantity 0 with a new cost), and the
     * engine asks that question rather than asking what kind of correction this
     * is. Slice 3's structural test forbids branching on the type, and adding
     * `Revaluation` as a seventh case required no engine change at all.
     */
    public function changesValueOnly(): bool
    {
        return $this->new_unit_cost !== null && (float) $this->quantity === 0.0;
    }

    /**
     * Whether a decrease without a named batch takes expired batches before
     * FIFO (14 Oct 2026, INV-001). Asked of the record so the engine never
     * branches on the type itself; the rule and its reasons live in
     * AdjustmentCause::takesExpiredFirst.
     */
    public function takesExpiredStockFirst(): bool
    {
        return AdjustmentCause::takesExpiredFirst($this->type);
    }

    /**
     * The batch this correction is about, when it is about one.
     *
     * ⚠ Expiry proposals raised before the batch was stored on the record name
     * it only as their source, and a pending one must still post against that
     * batch rather than FIFO.
     */
    public function namedBatchId(): ?int
    {
        if ($this->stock_batch_id !== null) {
            return (int) $this->stock_batch_id;
        }

        return $this->source_type === StockBatch::class && $this->source_id !== null
            ? (int) $this->source_id
            : null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What produced this: a stock count line, the expiry sweep, or nothing for a
     * manual correction.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * ⚠ Plural, and that is the point. A decrease spanning three cost layers
     * writes three movements; the flow this replaces recorded only the first.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'adjustment_id');
    }
}
