<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one adjustment settles one invoice or one bill. */
class PartyAdjustmentAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'party_adjustment_id', 'sales_invoice_id', 'supplier_bill_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class, 'party_adjustment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
