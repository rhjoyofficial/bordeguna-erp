<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_invoice_id',
        'delivery_line_id',
        'billed_delivery_for_order_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        // ⚠ The counter's price trail (21 Sep 2026): what the list said, that
        // the price was changed, why, and who. Null / false on a line sold at
        // its list price, and on every line raised from a challan or an order.
        'list_price',
        'price_overridden',
        'override_reason',
        'overridden_by',
        'line_discount',
        'line_net',
        'tax_class_id',
        'tax_rate',
        'tax_amount',
        'prices_include_tax',
        'discount_share',
        'tax_on_discount',
        'cost_released',
    ];

    protected $attributes = [
        'line_discount' => 0,
        'price_overridden' => false,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'cost_released' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'list_price' => 'decimal:4',
            'price_overridden' => 'boolean',
            'line_discount' => 'decimal:4',
            'line_net' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'prices_include_tax' => 'boolean',
            'discount_share' => 'decimal:4',
            'tax_on_discount' => 'decimal:4',
            'cost_released' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Who changed this line's price, or discounted it, at the counter. */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * The order whose agreed delivery charge this line bills. ⚠ Such a line
     * credits 4300 Delivery Charges Income, never sales revenue.
     */
    public function deliveryChargeFor(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'billed_delivery_for_order_id');
    }

    public function isDeliveryCharge(): bool
    {
        return $this->billed_delivery_for_order_id !== null;
    }
}
