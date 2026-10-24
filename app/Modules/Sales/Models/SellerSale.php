<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ What a retail seller reported he sold (initial plan B21, R109; Joy's
 * Q3 = A, 24 Oct 2026). The sale itself is an ordinary counter sale from his
 * seller stock (RecordCounterSale: challan, invoice, receipt in one
 * transaction), so revenue, cost of goods sold and the customer ledger post
 * exactly as at the counter. This row only says whose sale it was and where
 * the money went.
 *
 *   payment `collected`      he took the money: a receipt into his Employee
 *                            cash account (Joy's Q1), or another account
 *   payment `seller_owes`    he has not paid it in and answers for it: the
 *                            invoice stays open on his own staff customer
 *                            account (a receivable from the employee)
 *   payment `on_account`     a named credit customer owes it: the invoice
 *                            stays open on that customer
 *
 * ⚠ No reversal columns. A wrong report is undone by the invoice's own Void
 * sale (the corrections engine: receipt, invoice and challan reversed, the
 * goods back into his seller stock). Every total asks the invoice.
 */
class SellerSale extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const PAY_COLLECTED = 'collected';

    public const PAY_SELLER_OWES = 'seller_owes';

    public const PAY_ON_ACCOUNT = 'on_account';

    public const BASIS_TRADE = 'trade';

    public const BASIS_CUSTOMER = 'customer';

    protected $fillable = [
        'business_id',
        'number',
        'employee_id',
        'seller_warehouse_id',
        'sold_on',
        'customer_id',
        'sold_to',
        'price_basis',
        'payment',
        'money_account_id',
        'sales_invoice_id',
        'total',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sold_on' => 'date:Y-m-d',
            'total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'employee_id', 'sold_on', 'customer_id', 'payment', 'sales_invoice_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** Reports whose invoice still stands (not voided or credited). */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereHas('invoice', fn (Builder $q) => $q->whereNull('reversed_at'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function sellerWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'seller_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
