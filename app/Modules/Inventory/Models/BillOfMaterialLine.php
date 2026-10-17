<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a recipe, in the component's own stock unit.
 *
 * ⚠⚠ C-BOM-5. `wastage_percent` inflates the SUGGESTION and never leaves this
 * table. It reaches no journal, no stock movement and no batch: what actually
 * left the warehouse is the run's own measured consumption and its own waste
 * figure, and a standard allowance is not evidence of either.
 *
 * ⚠ C-BOM-4, amended 17 Oct 2026 (Phase 1 Part B). `quantity_per_output` is
 * STILL per the parent's `output_quantity` units of output, in THIS
 * component's stock unit, and it is still the only figure the run reads. What
 * changed: the line now also keeps what the person TYPED (`entered_quantity`
 * in `entered_unit_id`: 1,000 g on a component stocked in kg), and the
 * stock-unit figure is worked out ONCE, on save, by UnitConverter, from the
 * global metric factors or the product's own sourced row. Nothing converts at
 * run time, which is the part of C-BOM-4 that mattered.
 *
 * ⚠ The two entered columns are nullable in this release: rows that existed
 * before them were backfilled (stock unit, same figure) and the accessors
 * below fall back the same way, so a row the backfill has not reached reads
 * exactly as it did before. The NOT NULL follows once the live backfill is
 * reconciled (REPLY-1 safeguards).
 */
class BillOfMaterialLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'bill_of_material_id',
        'component_product_id',
        'quantity_per_output',
        'entered_quantity',
        'entered_unit_id',
        'wastage_percent',
        'note',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_output' => 'decimal:4',
            'entered_quantity' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /** What was typed, or the stock figure for a row written before entered units existed. */
    public function enteredQuantity(): string
    {
        return (string) ($this->entered_quantity ?? $this->quantity_per_output);
    }

    public function enteredUnitId(): ?int
    {
        return $this->entered_unit_id ?? $this->componentProduct?->unit_of_measure_id;
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'entered_unit_id');
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
