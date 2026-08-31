<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One product on one count sheet. `expected_quantity` is the book when the
 * count started; `live_quantity` is the book at completion, the figure the
 * variance was actually sized against (C-CNT-2); `counted_quantity` is what
 * the human found, null meaning "nobody looked" (C-CNT-3).
 */
class StockCountLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_count_id',
        'product_id',
        'expected_quantity',
        'counted_quantity',
        'live_quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'live_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The correction this line produced, if its variance was not zero. */
    public function adjustment(): MorphOne
    {
        return $this->morphOne(InventoryAdjustment::class, 'source');
    }
}
