<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consumed input of a production run, in the product's own stock unit.
 *
 * ⚠ `waste_quantity` is PART of `quantity_consumed`, not in addition to it:
 * 100 kg consumed of which 2 kg spilled. The waste share never became product,
 * but it certainly left the warehouse, and both facts are recorded.
 */
class ProductionRunLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'production_run_id',
        'product_id',
        'quantity_consumed',
        'waste_quantity',
        'consumed_value',
        'waste_value',
    ];

    protected function casts(): array
    {
        return [
            'quantity_consumed' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
            'consumed_value' => 'decimal:4',
            'waste_value' => 'decimal:4',
        ];
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
