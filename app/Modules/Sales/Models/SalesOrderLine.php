<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_delivered',
        'unit_price',
        'price_note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'quantity_delivered' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The challans that fulfilled (part of) this line.
     *
     * ⚠ Written in the same commit as the column that makes it possible -
     * pattern 7 of the guard lessons, same note as DeliveryLine::movements().
     */
    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class, 'sales_order_line_id');
    }

    /**
     * ⚠ Ordered but not yet delivered. On an open order this is what is owed;
     * on a Closed (short-closed) order it is the recorded shortfall, visible
     * forever with no per-line mutation. The order book reports this number.
     */
    public function quantityRemaining(): string
    {
        return number_format(
            (float) $this->quantity_ordered - (float) $this->quantity_delivered,
            4, '.', ''
        );
    }
}
