<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one credit note settles one specific bill.
 *
 * ⚠ The same shape as SupplierPaymentAllocation, deliberately: a credit
 * settles SPECIFIC bills, which is what keeps Accounts Payable a subsidiary
 * ledger where "what does this supplier's balance consist of" has an answer.
 */
class SupplierCreditNoteAllocation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'supplier_credit_note_id',
        'supplier_bill_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SupplierCreditNote::class, 'supplier_credit_note_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
