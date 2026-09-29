<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money spent on running the business, with a receipt behind it.
 *
 * ⚠ Paid at the moment it is recorded. An expense taken on credit is an accrual
 * and a different event; see the migration for why that is deferred rather than
 * bolted on here.
 */
class ExpenseVoucher extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    /**
     * The receipts, bills and screenshots proving the money was really spent.
     *
     * ⚠⚠ THE VOUCHER WAS THE ONE MONEY DOCUMENT WITH NOWHERE TO PUT THE PROOF.
     * Supplier bills, disbursement batches, adjustments and employee records
     * could all carry a file; the document covering rent, electricity, hosting
     * and every small cash payment could not. A voucher without its receipt is
     * a number somebody typed.
     */
    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = [
        'business_id',
        'voucher_number',
        'spent_on',
        'money_account_id',
        'supplier_id',
        'paid_to',
        'total',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d',
            'total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'spent_on', 'money_account_id', 'total', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseVoucherLine::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who the money went to, however the system knows them.
     */
    public function payee(): string
    {
        return $this->supplier?->name ?? $this->paid_to ?? 'Unnamed payee';
    }
}
