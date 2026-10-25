<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ⭐ One raw material batch a sample make consumed, at its cost (Samples, 25 Oct 2026). */
class SampleMakeInput extends Model
{
    protected $fillable = ['sample_make_id', 'product_id', 'stock_batch_id', 'quantity', 'unit_cost', 'cost_paisa', 'stock_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'cost_paisa' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
