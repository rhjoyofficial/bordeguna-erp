<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a seller's hand-over or return sheet: the quantity, the
 * Trade Price printed on the paper (a hand-over), the cost that actually
 * moved, and the stock transfer that moved it.
 */
class SellerTransferLine extends Model
{
    protected $fillable = [
        'seller_transfer_id',
        'product_id',
        'quantity',
        'trade_price',
        'cost_value',
        'stock_transfer_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'trade_price' => 'decimal:4',
            'cost_value' => 'decimal:4',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(SellerTransfer::class, 'seller_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }
}
