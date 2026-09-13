<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money physically going back to a customer (C-SR-6). Dr AR (party = customer)
 * / Cr the money account.
 *
 * ⚠ Linked to a return OR to an order, exactly one. A return-linked refund is
 * capped by the return's net credit less refunds already made. An order-linked
 * refund pays back money the customer paid for an order that will not be
 * invoiced for all of it (SSLCommerz slice P1), capped by what the order still
 * holds. A refund with neither is a goodwill payment, a different event Joy kept
 * out of scope.
 */
class CustomerRefund extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'customer_id',
        'sales_return_id',
        'sales_order_id',
        'money_account_id',
        'amount',
        'refunded_on',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'refunded_on' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'sales_return_id', 'sales_order_id', 'money_account_id', 'refunded_on', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountMoney(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
