<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed market price change, written once and never touched again.
 *
 * ⚠⚠ IMMUTABLE. The model refuses an update and a delete: an invoice priced
 * at Tk 170 at 10:15 is explained by this row, and a row that can be edited
 * explains nothing. A correction is a NEW confirmation with its own row.
 */
class ProductPriceHistory extends Model
{
    use BelongsToBusiness;

    protected $table = 'product_price_history';

    public const SOURCE_RECEIPT = 'receipt';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'business_id',
        'product_id',
        'channel',
        'price_group_id',
        'source',
        'goods_receipt_line_id',
        'stock_batch_id',
        'cost_snapshot',
        'prior_price',
        'suggested_price',
        'new_price',
        'markup_percent',
        'min_margin_percent',
        'rounding_step',
        'is_override',
        'override_reason',
        'product_price_id',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_snapshot' => 'decimal:4',
            'prior_price' => 'decimal:4',
            'suggested_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'min_margin_percent' => 'decimal:4',
            'rounding_step' => 'decimal:2',
            'is_override' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // ⚠ The one write allowed after creation: the live row's id, filled in
        // the same transaction that created both (ConfirmMarketPrice).
        static::updating(function (self $row) {
            $allowed = ['product_price_id', 'updated_at'];

            if (array_diff(array_keys($row->getDirty()), $allowed) !== []) {
                throw new DomainException('A price history row is never edited. Confirm a new price instead.');
            }
        });

        static::deleting(fn () => throw new DomainException('A price history row is never deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function productPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
