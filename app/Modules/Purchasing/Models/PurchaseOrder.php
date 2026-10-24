<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Enums\PaymentStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A PO is always Approvable (see Approvable concern) — Owner/Manager
 * self-approve immediately on creation (see CreatePurchaseOrder), Staff-
 * created POs sit pending until reviewed. Unlike StockRequest, approving a
 * PO has no domain side effect by itself — it only makes the PO eligible
 * for RecordGoodsReceipt.
 */
class PurchaseOrder extends Model implements WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, HasAttachments, HasFactory, LogsActivity, WithdrawsBySendingBack;

    /** ⭐ PR1: the supplier's quotation, a Mushak 6.3 photo, anything the order rests on. */
    public const ATTACHMENTS_COLLECTION = 'po-documents';

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'po_number',
        'supplier_id',
        'warehouse_id',
        'status',
        // ⚠⚠ RETIRED 21 Sep 2026, kept only so old rows still read. It was a
        // hand-set "Unpaid / Partially paid / Paid" dropdown that moved no money
        // and reached no ledger, so a PO could say "Paid" while Accounts Payable
        // said the supplier was owed in full. Nothing writes it any more; every
        // screen derives payment from the order's supplier bills instead.
        'payment_status',
        // ⭐ D-G, 18 Oct 2026: the day the order was placed with the supplier,
        // often earlier than the day it was typed in (created_at).
        'order_date',
        'expected_date',
        'note',
        // ⭐ Ruling P6: this order's own payment terms; NULL means the supplier's.
        'payment_terms_type',
        'payment_terms_days',
        'created_by',
        // ⭐ Purchase redesign PR1 (18 Oct 2026): the full order page.
        'supplier_reference',
        'buyer_id',
        'terms_text',
        'discount_type',
        'discount_value',
        'goods_paisa',
        'discount_paisa',
        'tax_total',
        'charges_paisa',
        'supplier_charges_paisa',
        'rounding_paisa',
        'grand_total_paisa',
        'vat_in_cost',
        // ⭐ Corrections wave 1 (19 Oct 2026): the "Edited" badge after Fix details.
        'details_fixed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'discount_value' => 'decimal:4',
            'goods_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'charges_paisa' => 'integer',
            'supplier_charges_paisa' => 'integer',
            'rounding_paisa' => 'integer',
            'grand_total_paisa' => 'integer',
            'vat_in_cost' => 'boolean',
            'details_fixed_at' => 'datetime',
        ];
    }

    /**
     * ⚠ 18 Oct 2026 (D-G): the column is NOT NULL, and an order written by any
     * path that does not name a day (a seeder, an import, an older caller) was
     * placed on the day it is written. CreatePurchaseOrder always names it.
     */
    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order) {
            if ($order->order_date === null) {
                $order->order_date = now()->toDateString();
            }
        });
    }

    /** The order date as Y-m-d (Dhaka calendar), or null on an unsaved row. */
    public function orderDateString(): ?string
    {
        return $this->order_date?->toDateString();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'order_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** ⭐ PR1: freight, carriage, labour, insurance, duty, other (PurchaseOrderCharge). */
    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseOrderCharge::class)->orderBy('sort')->orderBy('id');
    }

    /** ⭐ PR1: the planned part payments. */
    public function paymentSchedule(): HasMany
    {
        return $this->hasMany(PurchaseOrderPaymentSchedule::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * What the supplier is owed on this order: the stored grand total, or for
     * an order saved before the full page (nothing stored yet) its lines.
     */
    public function grandTotal(): Money
    {
        if ((int) $this->grand_total_paisa !== 0 || (int) $this->goods_paisa !== 0) {
            return Money::ofMinor((int) $this->grand_total_paisa);
        }

        return Money::sum($this->lines->map(fn (PurchaseOrderLine $line) => $line->lineTotal())->all())
            ->plus(Money::ofTaka((string) ($this->tax_total ?? '0')));
    }

    /**
     * ⭐ PR1: the full page edits an order only while nothing has happened to
     * it yet: open, nothing received, nothing billed. After that the receipt,
     * bill and return screens are the way to change what happened.
     */
    public function isEditable(): bool
    {
        return $this->status === PurchaseOrderStatus::Open
            && $this->lines->every(fn (PurchaseOrderLine $line) => (float) $line->quantity_received <= 0 && (float) $line->quantity_invoiced <= 0)
            && ! $this->bills()->exists();
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * The supplier's invoices raised against this order. Whether the order is
     * paid is read from THESE (their allocations and credit notes), never from
     * the old `payment_status` column, which nothing ever tied to money.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function isReceivable(): bool
    {
        return $this->approvalRequest->status === ApprovalStatus::Approved
            && in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::PartiallyReceived], true);
    }

    /**
     * True once every line's received quantity has been fully returned (or
     * nothing was ever received) — the gate CancelPurchaseOrder uses to allow
     * cancelling a partially/fully received order once it's genuinely being
     * abandoned rather than left in limbo.
     */
    public function hasNoOutstandingReceivedStock(): bool
    {
        return $this->lines->every(fn (PurchaseOrderLine $line) => (float) $line->quantityReturnable() <= 0.0);
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    /** The order's own Withdraw rules (PurchaseOrderActionPolicy), asked again under the lock. */
    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        $availability = app(PurchaseOrderActionPolicy::class)->availability($this->loadMissing('approvalRequest'), $by, DocumentAction::Withdraw);

        return $availability->allowed ? null : ($availability->blockers[0]->message ?? 'It cannot be withdrawn now.');
    }

    public function withdrawOutcome(): string
    {
        return 'draft';
    }

    public function withdrawLabel(): string
    {
        return 'Purchase order '.$this->po_number;
    }
}
