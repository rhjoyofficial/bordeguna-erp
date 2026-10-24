<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ Initial plan B8 (Joy, 24 Oct 2026; Q8 = B): one batch the person picked
 * for one input line of a production run, and how much of the line comes out
 * of it, in the product's own stock unit. Confirm takes exactly these.
 */
class ProductionRunLineBatch extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'production_run_line_id',
        'stock_batch_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionRunLine::class, 'production_run_line_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
