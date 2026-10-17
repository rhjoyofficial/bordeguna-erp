<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consumed input of a production run, in the product's own stock unit.
 *
 * ⚠⚠ C-BOM-3. `standard_quantity` is what the recipe SUGGESTED when the run was
 * drafted, copied at that moment. NULL means the recipe never mentioned this
 * component - a different fact from "suggested zero", and the line an operator
 * added by hand is the interesting one. Nothing recomputes it: editing the
 * recipe afterwards must not change what a past run was measured against.
 *
 * ⚠ `waste_quantity` is PART of `quantity_consumed`, not in addition to it:
 * 100 kg consumed of which 2 kg spilled. The waste share never became product,
 * but it certainly left the warehouse, and both facts are recorded.
 *
 * ⭐ 17 Oct 2026 (Phase 1 Part B): the line FREEZES the conversion it was
 * drafted with, the way a batch freezes its purchase factor (C-UOM-4):
 * `entered_quantity` in `entered_unit_id`, and `conversion_factor` = stock
 * units per one entered unit. `quantity_consumed` and `waste_quantity` stay
 * in the stock unit and are what FIFO consumes. Editing the product's
 * conversion row next year must not change what this run says it used, and
 * confirm() refuses a line whose factor was never validated.
 */
class ProductionRunLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'production_run_id',
        'product_id',
        'quantity_consumed',
        'entered_quantity',
        'entered_unit_id',
        'conversion_factor',
        'standard_quantity',
        'waste_quantity',
        'consumed_value',
        'waste_value',
    ];

    protected function casts(): array
    {
        return [
            'quantity_consumed' => 'decimal:4',
            'entered_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:8',
            'standard_quantity' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
            'consumed_value' => 'decimal:4',
            'waste_value' => 'decimal:4',
        ];
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'entered_unit_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class, 'production_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
