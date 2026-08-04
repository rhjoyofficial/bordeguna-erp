<?php

namespace App\Modules\Sales\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'delivery_id',
        'product_id',
        'quantity',
        'quantity_invoiced',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movementLinks(): HasMany
    {
        return $this->hasMany(DeliveryLineMovement::class);
    }

    /**
     * The stock movements this line produced - one per FIFO layer consumed.
     *
     * ⚠ A relation, in the same commit as the column that makes it possible.
     * Pattern 7 of the guard lessons: `purchase_return_lines.stock_movement_id`
     * was added for a future consumer and the relation was never written, so
     * nothing could traverse it until somebody noticed months later.
     */
    public function movements(): BelongsToMany
    {
        return $this->belongsToMany(
            StockMovement::class,
            'delivery_line_movements',
            'delivery_line_id',
            'stock_movement_id',
        );
    }

    /**
     * What this line's goods cost, at the layers actually taken.
     */
    public function costValue(): Money
    {
        return $this->movements->reduce(
            fn (Money $carry, StockMovement $movement) => $carry->plus(
                Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost)
            ),
            Money::zero(),
        );
    }

    /**
     * ⚠ Delivered but not yet billed. The GDNI balance is the money version of
     * this number, and a challan nobody invoiced is the thing it reports.
     */
    public function quantityUninvoiced(): string
    {
        return number_format(
            (float) $this->quantity - (float) $this->quantity_invoiced,
            4, '.', ''
        );
    }
}
