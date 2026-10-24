<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A customer's advance (Joy's initial plan R90, B11, 24 Oct 2026): money of
 * theirs we hold before there is an invoice to take it. A LIABILITY until an
 * invoice uses it or it is paid back: Cr 2150 Advances from Customers with the
 * customer as party, never revenue and never a receivable.
 *
 *   receipt      money received on account (no invoice, no order):
 *                Dr the money account / Cr 2150
 *   from_order   what a cancelled order still held, moved to their advance
 *                (no money moves): Dr 1140 / Cr 2150
 *   from_return  a sales return's open credit kept for the customer
 *                (integration, 25 Oct 2026): Dr 1140 / Cr 2150
 *
 * ⚠⚠ What is still open is DERIVED (amount less live applications and
 * refunds), never stored, as SupplierAdvance does.
 */
class CustomerAdvance extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    public const RECEIPT = 'receipt';

    public const FROM_ORDER = 'from_order';

    public const FROM_RETURN = 'from_return';

    /** The kinds that leave Accounts Receivable (Dr 1140 / Cr 2150), no money moving. */
    public const FROM_RECEIVABLE = [self::FROM_ORDER, self::FROM_RETURN];

    public const KINDS = [
        self::RECEIPT => 'Advance received',
        self::FROM_ORDER => 'Moved from a cancelled order',
        self::FROM_RETURN => 'Kept from a sales return',
    ];

    protected $fillable = [
        'business_id',
        'number',
        'kind',
        'customer_id',
        'money_account_id',
        'sales_order_id',
        'sales_return_id',
        'customer_receipt_id',
        'received_on',
        'amount',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'kind', 'customer_id', 'money_account_id', 'sales_order_id', 'received_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CustomerAdvanceApplication::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerAdvanceRefund::class);
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function appliedTotal(): Money
    {
        return Money::ofTaka((string) ($this->applications()->live()->sum('amount') ?: '0'));
    }

    public function refundedTotal(): Money
    {
        return Money::ofTaka((string) ($this->refunds()->live()->sum('amount') ?: '0'));
    }

    /** Still to be used on an invoice or paid back. Zero once reversed. */
    public function open(): Money
    {
        if ($this->isReversed()) {
            return Money::zero();
        }

        return Money::ofMinor($this->money()->minor - $this->appliedTotal()->minor - $this->refundedTotal()->minor);
    }

    public function isFromOrder(): bool
    {
        return $this->kind === self::FROM_ORDER;
    }

    public function isFromReturn(): bool
    {
        return $this->kind === self::FROM_RETURN;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
