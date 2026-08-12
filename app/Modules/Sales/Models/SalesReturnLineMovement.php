<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⚠⚠ THE CAPACITY LEDGER for layer walks, covering BOTH dispositions.
 *
 * One row per (return line, consumed OUT movement): how much of that layer's
 * consumption this return claimed, whether it physically went back to stock
 * (restored_movement_id carries the IN movement) or was written off (null -
 * destroyed goods write no movement), and the frozen layer cost the share was
 * valued at. Later returns derive each layer's remaining returnable share from
 * these rows; deriving it from movements alone would miss the write-offs and
 * restore the same layer twice.
 */
class SalesReturnLineMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_return_line_id',
        'stock_movement_id',
        'restored_movement_id',
        'quantity',
        'disposition',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'disposition' => SalesReturnDisposition::class,
        ];
    }

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(SalesReturnLine::class, 'sales_return_line_id');
    }

    /** The sale's OUT movement this share reverses. */
    public function issueMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    /** The IN movement written for a back-to-stock share; null for write-offs. */
    public function restoredMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'restored_movement_id');
    }
}
