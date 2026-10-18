<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submission of Purchases > Buy and receive (ruling P4, 18 Oct 2026).
 *
 * ⚠⚠ NOT an accounting document. It posts nothing and owns no money; the
 * order, receipt, landed cost, bill and payment it links are the documents,
 * each made by its own action with its own number and journal. This row only
 * remembers the request key (idempotency) and where the chain stopped.
 */
class QuickPurchase extends Model
{
    use BelongsToBusiness;

    public const COMPLETED = 'completed';

    /** The order waits for a person who may approve it; nothing was received. */
    public const AWAITING_ORDER_APPROVAL = 'awaiting_order_approval';

    /** Received; the whole purchase waits for a second person before it is billed. */
    public const AWAITING_APPROVAL = 'awaiting_approval';

    /** Received; the bill waits for a second person, so nothing was paid. */
    public const AWAITING_BILL_APPROVAL = 'awaiting_bill_approval';

    /** Received and billed; the payment waits for a second person. */
    public const AWAITING_PAYMENT_APPROVAL = 'awaiting_payment_approval';

    protected $fillable = [
        'business_id',
        'request_key',
        'supplier_id',
        'purchased_on',
        'status',
        'held_reason',
        'purchase_order_id',
        'goods_receipt_id',
        'landed_cost_id',
        'supplier_bill_id',
        'supplier_payment_id',
        // ⭐ Ruling P7: the PA- row the bill or payment waits as.
        'purchase_approval_id',
        'goods_paisa',
        'extra_costs_paisa',
        'paid_paisa',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date:Y-m-d',
            'goods_paisa' => 'integer',
            'extra_costs_paisa' => 'integer',
            'paid_paisa' => 'integer',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** ⭐ Ruling P7: the PA- row the bill or payment of this purchase waits as. */
    public function purchaseApproval(): BelongsTo
    {
        return $this->belongsTo(PurchaseApproval::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
