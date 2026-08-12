<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The FACT of what one return credited against one invoice line: quantity,
 * gross at the price that invoice actually charged (line discount included),
 * the pro-rated share of any invoice-level discount clawed back, and the net.
 *
 * ⚠ Valuation, not application - which invoice the net credit is APPLIED to
 * lives in sales_return_allocations, capped at outstanding. These rows are
 * uncapped and factual: they drive the journal and the remainder discipline.
 */
class SalesReturnInvoiceLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_return_line_id',
        'sales_invoice_line_id',
        'quantity',
        'gross',
        'discount',
        'net',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'gross' => 'decimal:4',
            'discount' => 'decimal:4',
            'net' => 'decimal:4',
        ];
    }

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(SalesReturnLine::class, 'sales_return_line_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sales_invoice_line_id');
    }
}
