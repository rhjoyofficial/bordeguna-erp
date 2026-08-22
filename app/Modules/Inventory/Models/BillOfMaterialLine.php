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
 * ⚠ C-BOM-4. `quantity_per_output` is per the parent's `output_quantity` units
 * of output, in THIS component's stock unit, and nothing converts.
 */
class BillOfMaterialLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'bill_of_material_id',
        'component_product_id',
        'quantity_per_output',
        'wastage_percent',
        'note',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_output' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'sort_order' => 'integer',
        ];
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
