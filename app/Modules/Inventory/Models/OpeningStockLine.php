<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Support\StockValue;
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
        'value_paisa',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date:Y-m-d',
            'value_paisa' => 'integer',
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
     *
     * ⭐ On a corrected line this is still the batch's ORIGINAL opening
     * receipt: the batch is the line's identity, whatever the correction moved.
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function value(): Money
    {
        // ⭐ Corrections wave 1 (20 Oct 2026): a line of a corrected opening
        // stock carries its value, summed from every movement that makes it up
        // (OpeningStockCorrector). Lines recorded the ordinary way keep valuing
        // themselves from their one movement, exactly as before.
        if ($this->value_paisa !== null) {
            return Money::ofMinor((int) $this->value_paisa);
        }

        $movement = $this->stockMovement;

        if ($movement === null) {
            return Money::zero();
        }

        return StockValue::of($movement)->absolute();
    }
}
