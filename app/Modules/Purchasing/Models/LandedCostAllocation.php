<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The evidence of ONE capitalised line's share on ONE stock batch, written at
 * posting and never recomputed: the quantities the split was made from, the
 * share, how it divided between stock on hand and stock consumed, the cost the
 * batch was restated to, and the revaluation movement that did it.
 *
 * ⚠ Stored because the quantity on hand changes every day; deriving these later
 * would give a different answer for the same event (the value_delta lesson).
 */
class LandedCostAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'landed_cost_line_id',
        'goods_receipt_line_id',
        'stock_batch_id',
        'quantity_received',
        'quantity_on_hand',
        'quantity_consumed',
        // ⭐ 18 Oct 2026: the units, grams or taka this batch's share was
        // computed from, and which of the three (basis_unit).
        'basis_quantity',
        'basis_unit',
        'share_paisa',
        'on_hand_share_paisa',
        'consumed_share_paisa',
        'new_unit_cost',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_on_hand' => 'decimal:4',
            'quantity_consumed' => 'decimal:4',
            'basis_quantity' => 'decimal:4',
            'share_paisa' => 'integer',
            'on_hand_share_paisa' => 'integer',
            'consumed_share_paisa' => 'integer',
            'new_unit_cost' => 'decimal:4',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(LandedCostLine::class, 'landed_cost_line_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
