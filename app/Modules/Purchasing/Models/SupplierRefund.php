<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Ruling P5 (19 Oct 2026): money a supplier pays back because THEY owe US
 * on Accounts Payable (a debit balance on 2110 with them as party, from a
 * voucher, an opening balance or a set-off). SREF-2026-27-0001.
 *
 * ⚠ Not an advance refund: what a supplier holds of ours in 1160 Advances is
 * refunded by SupplierAdvanceRefund (SARF). This one relieves 2110 only.
 * Corrected by reversal with a reason, never edited.
 */
class SupplierRefund extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'supplier_id',
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
            ->logOnly(['number', 'supplier_id', 'money_account_id', 'received_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
