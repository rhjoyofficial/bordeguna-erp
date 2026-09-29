<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
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
 */
class CustomerReceipt extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'customer_id',
        'sales_order_id',
        // The voucher that created this receipt (finance decision C3), if any.
        'voucher_id',
        'money_account_id',
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
