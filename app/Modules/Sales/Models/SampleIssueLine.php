<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ⭐ One product on a sample hand-out (Samples, 25 Oct 2026): the batch the
 * person chose (or FEFO when none), the quantity, and once posted its cost
 * and the movements it wrote (FEFO may take it from several batches).
 */
class SampleIssueLine extends Model
{
    protected $fillable = ['sample_issue_id', 'product_id', 'stock_batch_id', 'quantity', 'cost_paisa', 'sort'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'cost_paisa' => 'integer'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(SampleIssue::class, 'sample_issue_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function movements(): BelongsToMany
    {
        return $this->belongsToMany(StockMovement::class, 'sample_issue_line_movements', 'sample_issue_line_id', 'stock_movement_id')->withTimestamps();
    }
}
