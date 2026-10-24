<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A customer's advance paid back, in full or in part (B11, 24 Oct 2026):
 * Dr 2150 Advances from Customers (party = customer) / Cr the money account.
 *
 * ⚠ Never more than is still open on the advance (or on the customer's
 * on-account balance, when `customer_advance_id` is null).
 */
class CustomerAdvanceRefund extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'customer_id',
        'customer_advance_id',
        'money_account_id',
        'refunded_on',
        'amount',
        'reference',
        'source_note',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'refunded_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'customer_id', 'customer_advance_id', 'money_account_id', 'refunded_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(CustomerAdvance::class, 'customer_advance_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
