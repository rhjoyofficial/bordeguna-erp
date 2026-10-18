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
 * A supplier giving back an advance, in full or in part (ruling P2): Dr the
 * money account / Cr 1160 Advances to Suppliers (party = supplier).
 *
 * ⚠ Never more than is still open on the advance (or on the supplier's
 * on-account balance, when `supplier_advance_id` is null).
 */
class SupplierAdvanceRefund extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'supplier_id',
        'supplier_advance_id',
        'money_account_id',
        'refunded_on',
        'amount',
        'reference',
        'note',
        'created_by',
        // ⭐ The Receipt voucher that made it (party ledgers, 20 Oct 2026).
        'voucher_id',
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
            ->logOnly(['number', 'supplier_id', 'supplier_advance_id', 'money_account_id', 'refunded_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
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
