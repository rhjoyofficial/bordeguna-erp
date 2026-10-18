<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch's part of a supplier discount, frozen as it was posted: what it
 * took off the units on hand (and the movement that did it) and what it
 * credited to cost of sales for the units already gone. The evidence a
 * reversal and an auditor both read.
 */
class SupplierDiscountAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'supplier_discount_id', 'goods_receipt_line_id', 'stock_batch_id',
        'quantity_received', 'quantity_on_hand', 'quantity_consumed',
        'share_paisa', 'on_hand_share_paisa', 'consumed_share_paisa',
        'old_unit_cost', 'new_unit_cost', 'stock_movement_id', 'reversal_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_on_hand' => 'decimal:4',
            'quantity_consumed' => 'decimal:4',
            'share_paisa' => 'integer',
            'on_hand_share_paisa' => 'integer',
            'consumed_share_paisa' => 'integer',
            'old_unit_cost' => 'decimal:4',
            'new_unit_cost' => 'decimal:4',
        ];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(SupplierDiscount::class, 'supplier_discount_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
