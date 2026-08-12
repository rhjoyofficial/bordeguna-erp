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
        'quantity_returned',
        'quantity_returned_uninvoiced',
        'sales_order_line_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_invoiced' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            'quantity_returned_uninvoiced' => 'decimal:4',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * The order line this delivery fulfils (part of), or null for a direct
     * delivery - which is every delivery that existed before sales orders,
     * and every counter sale forever. Mirrors
     * goods_receipt_lines.purchase_order_line_id.
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
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
     * ⚠ Delivered but not yet billed - the BILLABLE remainder. The GDNI balance
     * is the money version of this number, and a challan nobody invoiced is the
     * thing it reports.
     *
     * ⚠⚠ C-SR-4: a return relieves the uninvoiced portion FIRST, so goods
     * returned before billing subtract here and month end simply bills less.
     * Without this subtraction, month-end invoicing would bill goods that are
     * physically back on the shelf.
     */
    public function quantityUninvoiced(): string
    {
        return number_format(
            (float) $this->quantity
                - (float) $this->quantity_invoiced
                - (float) $this->quantity_returned_uninvoiced,
            4, '.', ''
        );
    }

    /**
     * What may still come back: delivered less already returned, either
     * disposition. The cap the return engine re-reads under lock.
     */
    public function quantityReturnable(): string
    {
        return number_format(
            (float) $this->quantity - (float) $this->quantity_returned,
            4, '.', ''
        );
    }

    /**
     * Invoiced and not yet returned-against - the pool a return's invoiced
     * portion credits, walked newest invoice line first.
     */
    public function quantityInvoicedReturnable(): string
    {
        return number_format(
            (float) $this->quantity_invoiced
                - ((float) $this->quantity_returned - (float) $this->quantity_returned_uninvoiced),
            4, '.', ''
        );
    }
}
