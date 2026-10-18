<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one set-off clears one bill or one invoice (18 Oct 2026). While
 * the set-off waits this is a proposal only; once it posts, the same figures
 * become the party adjustment's allocations.
 */
class SetOffLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'set_off_id', 'sales_invoice_id', 'supplier_bill_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function setOff(): BelongsTo
    {
        return $this->belongsTo(SetOff::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
