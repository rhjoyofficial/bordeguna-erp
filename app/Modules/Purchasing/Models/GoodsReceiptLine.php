<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Models\ProductPriceHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'goods_receipt_id',
        'purchase_order_line_id',
        'quantity_received',
        'stock_movement_id',
        // ⭐ Phase 2 / 1B (17 Oct 2026): on a market product's line, whether
        // the receiver confirmed today's price (`confirmed`) or left the old
        // one in force (`unconfirmed`), and the history row a confirmation
        // wrote. Null on every other product.
        'market_price_status',
        'market_price_history_id',
    ];

    public function marketPriceHistory(): BelongsTo
    {
        return $this->belongsTo(ProductPriceHistory::class, 'market_price_history_id');
    }

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
