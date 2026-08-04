<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which stock movements a delivery line produced.
 *
 * ⚠⚠ This exists because `stock_movements.reason_id` is a FOREIGN KEY to the
 * `reasons` table and could never have held a delivery-line id, and because one
 * line at FIFO consumes several batches and therefore writes several movements -
 * so the single `stock_movement_id` column that a goods receipt line uses does
 * not fit either.
 *
 * ⚠ The link lives on the Sales side pointing INTO Inventory, which is the
 * direction Purchasing already uses. Inventory never learns what a delivery is.
 */
class DeliveryLineMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'delivery_line_id',
        'stock_movement_id',
    ];

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
