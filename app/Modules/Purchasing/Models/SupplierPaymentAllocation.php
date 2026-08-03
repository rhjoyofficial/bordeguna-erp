<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment settled one bill.
 *
 * ⚠⚠ This is what makes Accounts Payable a LEDGER rather than a balance.
 * "Which invoices did this cheque pay" is the first question a supplier asks
 * when the two sides disagree, and without allocations the only honest answer
 * is a shrug.
 */
class SupplierPaymentAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'supplier_payment_id',
        'supplier_bill_id',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
