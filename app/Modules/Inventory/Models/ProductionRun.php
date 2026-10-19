<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One production run: consumed THESE inputs, produced THIS output batch, and
 * here is the waste we measured. The sixth sanctioned stock document.
 *
 * ⚠⚠ The run never converts units (C-MFG-2). Every quantity on this record is
 * declared reality in the product's own stock unit; `yield_check` is the only
 * place a declared content is compared with consumption, and it is a flag,
 * never a refusal and never an adjustment.
 *
 * See MANUFACTURING-PHASE1-PACKING-ARCHITECTURE.md.
 */
class ProductionRun extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'warehouse_id',
        'run_number',
        'status',
        'output_product_id',
        'output_quantity',
        'bill_of_material_id',
        'bill_of_material_version',
        'output_batch_id',
        'output_expiry_date',
        'output_mrp',
        'expense_waste',
        'consumed_value',
        'waste_value',
        'additional_cost_value',
        'output_value',
        'yield_check',
        'occurred_at',
        'note',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'reversed_by',
        'reversed_at',
        // Corrections wave 1 (20 Oct 2026).
        'reversed_on',
        'reversal_reason',
        'reversal_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductionRunStatus::class,
            'output_quantity' => 'decimal:4',
            'bill_of_material_version' => 'integer',
            'output_mrp' => 'decimal:4',
            'expense_waste' => 'boolean',
            'consumed_value' => 'decimal:4',
            'waste_value' => 'decimal:4',
            'additional_cost_value' => 'decimal:4',
            'output_value' => 'decimal:4',
            'yield_check' => 'array',
            'output_expiry_date' => 'date:Y-m-d',
            'occurred_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'reversed_on' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['run_number', 'status', 'warehouse_id', 'output_product_id', 'output_quantity', 'expense_waste', 'consumed_value', 'waste_value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * C-BOM-2. Provenance only. Nothing recomputes a run from this - the run's
     * own lines are what it consumed, and the recipe may have changed since.
     */
    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function outputBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'output_batch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionRunLine::class);
    }

    /** PR3: labour, overhead and other costs added to the output batch. */
    public function costs(): HasMany
    {
        return $this->hasMany(ProductionRunCost::class);
    }

    /**
     * ⚠ Plural and mixed: the OUT movements that consumed the inputs (one per
     * batch portion - FIFO traceability lives here) and the IN movement that
     * created the output batch. A reversal adds their mirrors.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'production_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === ProductionRunStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === ProductionRunStatus::Confirmed;
    }

    /** Whether the confirm-time yield comparison raised its flag (C-MFG-2). */
    public function yieldFlagged(): bool
    {
        return (bool) ($this->yield_check['flagged'] ?? false);
    }
}
