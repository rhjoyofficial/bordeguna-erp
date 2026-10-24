<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money moved between the business's own accounts.
 *
 * ⚠⚠ NOTHING IS EARNED OR SPENT HERE except the fee. The business is exactly as
 * rich after a transfer as before it, minus what the move cost. That is why a
 * transfer touches no income and no expense account other than the fee one -
 * treating a bank-to-till withdrawal as income would inflate revenue with the
 * business's own money.
 */
class MoneyTransfer extends Model
{
    // ⭐ Corrections wave 2 (24 Oct 2026): Reverse and Correct; kept and marked.
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'transfer_number',
        'transferred_on',
        'from_money_account_id',
        'to_money_account_id',
        'amount',
        'fee',
        'fee_account_id',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transferred_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_money_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id');
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fee_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What actually left the source: the amount that arrived, plus the cost of
     * moving it.
     */
    public function totalWithdrawn(): string
    {
        return bcadd((string) $this->amount, (string) $this->fee, 2);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'transfer_number', 'transferred_on', 'from_money_account_id',
                'to_money_account_id', 'amount', 'fee', 'fee_account_id', 'reference',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
