<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Paying commission that a settlement left owed: Dr Commission Payable (the
 * person) / Cr the money account. ⚠ No approval of its own: the money was
 * decided, and above the limit approved, when it was settled; this can only
 * pay up to what is still owed on that settlement.
 */
class CommissionPayment extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id', 'number', 'commission_settlement_id', 'paid_on', 'amount', 'money_account_id',
        'journal_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount' => 'decimal:4'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'paid_on', 'amount', 'money_account_id'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('commission_payment');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
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
