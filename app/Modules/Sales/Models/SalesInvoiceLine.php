<?php

namespace App\Modules\Sales\Models;

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
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'line_discount',
        'line_net',
        'tax_rate',
        'tax_amount',
        'cost_released',
    ];

    protected $attributes = [
        'line_discount' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'cost_released' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_discount' => 'decimal:4',
            'line_net' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
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
}
