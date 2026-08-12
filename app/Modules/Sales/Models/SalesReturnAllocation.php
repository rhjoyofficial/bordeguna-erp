<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a return's net credit is applied against one invoice - the
 * mirror of CustomerReceiptAllocation, subtracting instead of settling.
 * Residue the traced invoices could not absorb (already paid) stays
 * unallocated: credit-on-account, which reconciliation names and warns about
 * rather than hiding.
 */
class SalesReturnAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_return_id',
        'sales_invoice_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
