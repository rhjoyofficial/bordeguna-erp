<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product a harvest brought in: the main crop (paddy) or a by-product
 * (straw), with its share of the amount moved and the batch it made.
 */
class CropHarvestLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'crop_harvest_id',
        'product_id',
        'is_main',
        'quantity',
        'entered_quantity',
        'entered_unit_id',
        'conversion_factor',
        'cost_paisa',
        'unit_cost',
        'batch_number',
        'expiry_date',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'quantity' => 'decimal:4',
            'entered_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:8',
            'cost_paisa' => 'integer',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    public function cost(): Money
    {
        return Money::ofMinor((int) $this->cost_paisa);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(CropHarvest::class, 'crop_harvest_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'entered_unit_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
