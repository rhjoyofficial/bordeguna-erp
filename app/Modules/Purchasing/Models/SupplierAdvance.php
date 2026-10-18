<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money paid to a supplier before there is a bill to pay (ruling P2, 18 Oct
 * 2026). An ASSET until goods or a refund come back for it: Dr 1160 Advances to
 * Suppliers with the supplier as party, never an expense and never a payable.
 *
 * ⚠ `amount` is GROSS, what the supplier is credited with. TDS held back at
 * this stage is recorded beside it (`tds_amount`), so the bank pays the net and
 * the bill this advance later settles is never taxed again on the same base.
 *
 * ⚠⚠ What is still open is DERIVED (amount less live applications and
 * refunds), never stored, for the reason SupplierBill::paidTotal() gives.
 */
class SupplierAdvance extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    public const KINDS = [
        'payment' => 'Advance payment',
        'overpayment' => 'Overpayment kept as an advance',
        'opening' => 'Opening advance at cutover',
    ];

    protected $fillable = [
        'business_id',
        'number',
        'kind',
        'supplier_id',
        'purchase_order_id',
        'money_account_id',
        'supplier_payment_id',
        'paid_on',
        'amount',
        'tds_rate_id',
        'tds_amount',
        'vds_rate_id',
        'vds_amount',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'tds_amount' => 'decimal:4',
            'vds_amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'kind', 'supplier_id', 'purchase_order_id', 'money_account_id', 'paid_on', 'amount', 'tds_amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function tdsRate(): BelongsTo
    {
        return $this->belongsTo(WithholdingRate::class, 'tds_rate_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SupplierAdvanceApplication::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SupplierAdvanceRefund::class);
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    /** What left the money account: the advance less the tax held back. */
    public function netPaid(): Money
    {
        return Money::ofMinor($this->money()->minor
            - Money::ofTaka((string) $this->tds_amount)->minor
            - Money::ofTaka((string) $this->vds_amount)->minor);
    }

    public function appliedTotal(): Money
    {
        return Money::ofTaka((string) ($this->applications()->live()->sum('amount') ?: '0'));
    }

    public function refundedTotal(): Money
    {
        return Money::ofTaka((string) ($this->refunds()->live()->sum('amount') ?: '0'));
    }

    /** Still to be used against a bill or refunded. Zero once reversed. */
    public function open(): Money
    {
        if ($this->isReversed()) {
            return Money::zero();
        }

        return Money::ofMinor($this->money()->minor - $this->appliedTotal()->minor - $this->refundedTotal()->minor);
    }

    /**
     * The TDS held back on a part of this advance, in proportion: what an
     * application of `$part` carries with it as tax already withheld.
     */
    public function tdsOn(Money $part): Money
    {
        $tds = Money::ofTaka((string) $this->tds_amount);

        if ($tds->isZero() || $this->money()->isZero()) {
            return Money::zero();
        }

        return Money::ofMinor((int) round($tds->minor * $part->minor / $this->money()->minor));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
