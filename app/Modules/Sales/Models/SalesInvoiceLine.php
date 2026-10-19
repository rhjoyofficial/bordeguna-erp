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
        // ⭐ Corrections wave 1: equals billed_delivery_for_order_id while the
        // invoice stands; cleared by a full credit note (UNIQUE: billed once).
        'live_delivery_for_order_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        // ⚠ The counter's price trail (21 Sep 2026): what the list said, that
        // the price was changed, why, and who. Null / false on a line sold at
        // its list price, and on every line raised from a challan or an order.
        'list_price',
        'price_overridden',
        // ⭐ Phase 2 (17 Oct 2026): a staff purchase line (list_price = cap
        // reference price, unit_price = batch-cost staff price).
        'is_staff_sale',
        'is_owner_sale',
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
        'is_staff_sale' => false,
        'is_owner_sale' => false,
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
            'is_staff_sale' => 'boolean',
            'is_owner_sale' => 'boolean',
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

    /**
     * ⭐ Corrections wave 1: a delivery-charge line is written with its live
     * "billed once" key whoever writes it, so the UNIQUE index keeps refusing
     * a second live charge for one order at the database (only a full credit
     * note clears the key, CreditSalesInvoice).
     */
    protected static function booted(): void
    {
        static::creating(function (self $line) {
            if ($line->billed_delivery_for_order_id !== null && $line->live_delivery_for_order_id === null) {
                $line->live_delivery_for_order_id = $line->billed_delivery_for_order_id;
            }
        });
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
