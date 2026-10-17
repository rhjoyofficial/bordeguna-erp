<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on an owner withdrawal. Before approval: product and quantity.
 * At approval: the FIFO batch reserved for it, that batch's unit cost frozen,
 * the line value at cost, and the reference price (MRP, else the selling
 * price in force) with the difference, kept as evidence only (D8).
 *
 * ⚠ ONE batch per line. A quantity the FIFO head cannot cover is split into
 * sibling lines at approval, one per batch, each at its own cost, rather
 * than averaged (ruling Q1: the price is the cost of the batch that leaves).
 */
class OwnerWithdrawalLine extends Model
{
    use BelongsToBusiness;

    public const REFERENCE_MRP = 'mrp';

    public const REFERENCE_SELLING_PRICE = 'selling_price';

    public const REFERENCE_NONE = 'none';

    protected $fillable = [
        'business_id',
        'owner_withdrawal_id',
        'sort',
        'product_id',
        'quantity',
        'stock_reservation_id',
        'stock_batch_id',
        'unit_cost',
        'cost_paisa',
        'reference_source',
        'reference_price',
        'reference_paisa',
        'difference_paisa',
        'stock_movement_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'reference_price' => 'decimal:4',
            'cost_paisa' => 'integer',
            'reference_paisa' => 'integer',
            'difference_paisa' => 'integer',
        ];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(OwnerWithdrawal::class, 'owner_withdrawal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function cost(): Money
    {
        return Money::ofMinor((int) $this->cost_paisa);
    }

    public function reference(): ?Money
    {
        return $this->reference_paisa === null ? null : Money::ofMinor((int) $this->reference_paisa);
    }

    public function difference(): ?Money
    {
        return $this->difference_paisa === null ? null : Money::ofMinor((int) $this->difference_paisa);
    }
}
