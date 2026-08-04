<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'opening_stock_id',
        'product_id',
        'quantity',
        'unit_cost',
        'batch_number',
        'expiry_date',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * ⚠ The relation ships in the same commit as the column, so nothing can
     * traverse it later and find it missing. Pattern 7 of the guard lessons.
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function value(): Money
    {
        $movement = $this->stockMovement;

        if ($movement === null) {
            return Money::zero();
        }

        return Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);
    }
}
