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
 * Money in from a customer, against specific invoices.
 *
 * ⚠ The mirror of SupplierPayment, on purpose and in every detail.
 *
 * ⭐ Or money paid for an ORDER before its invoice exists (SSLCommerz slice P1):
 * `sales_order_id` names the order, and whatever is not yet allocated to one of
 * its invoices is held on the customer's account for it.
 *
 * ⭐ Reversible (corrections wave 1, 20 Oct 2026): a wrong receipt is reversed
 * or corrected, never deleted. It is KEPT and marked; a mirror journal undoes
 * it and its allocations are released (kept in `reversed_allocations`), so the
 * invoices it paid owe again. Every sum asks `live()` / `standingOn()`.
 */
class CustomerReceipt extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'customer_id',
        'sales_order_id',
        // The voucher that created this receipt (finance decision C3), if any.
        'voucher_id',
        'money_account_id',
        // ⭐ Phase 2 (17 Oct 2026): `cash` (money arrived in an account) or
        // `payroll_offset` (the staff purchase invoice cleared against salary,
        // no money account, posted by the payroll approval). `payroll_run_id`
        // names the run an offset belongs to, so a reopened run withdraws it.
        'kind',
        'payroll_run_id',
        'received_on',
        'amount',
        'reference',
        'note',
        'created_by',
    ];

    public const KIND_CASH = 'cash';

    public const KIND_PAYROLL_OFFSET = 'payroll_offset';

    protected $attributes = [
        'kind' => self::KIND_CASH,
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'reversed_allocations' => 'array',
        ];
    }

    public function isCash(): bool
    {
        return ($this->kind ?? self::KIND_CASH) === self::KIND_CASH;
    }

    public function isPayrollOffset(): bool
    {
        return $this->kind === self::KIND_PAYROLL_OFFSET;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'money_account_id', 'received_on', 'amount', 'reference'])
            ->logOnlyDirty()
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

    /**
     * The order this money was paid for, or null for a receipt against invoices.
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    public function amountMoney(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    /**
     * What of this receipt no invoice has taken yet. ⚠ Read live, never cached
     * on the row: an allocation written a second ago must be seen.
     */
    public function unallocated(): Money
    {
        $allocated = Money::ofTaka((string) ($this->allocations()->sum('amount') ?: '0'));

        return Money::ofMinor($this->amountMoney()->minor - $allocated->minor);
    }
}
