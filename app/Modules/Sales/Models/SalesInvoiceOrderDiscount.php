<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an order's agreed order-level discount one invoice billed.
 *
 * ⚠ Recorded rather than recomputed, for the reason `cost_released` is: rounded
 * shares do not sum back to the whole, so the invoice that finishes an order
 * bills the remainder - which is only exact if every earlier share is known.
 */
class SalesInvoiceOrderDiscount extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_invoice_id',
        'sales_order_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
