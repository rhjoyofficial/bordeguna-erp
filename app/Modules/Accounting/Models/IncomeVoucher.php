<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money that came in and was not a sale, with the proof behind it.
 *
 * ⚠ Received at the moment it is recorded. Income earned but not yet received
 * is a receivable, a different event with a different document.
 */
class IncomeVoucher extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    /**
     * The deposit slip, the bank advice, the scrap dealer's chit.
     *
     * ⚠⚠ Same reasoning as the expense voucher's receipts: a voucher without
     * its proof is a number somebody typed. Money arriving deserves the
     * evidence money leaving already had.
     */
    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = [
        'business_id',
        'voucher_number',
        'received_on',
        'money_account_id',
        'customer_id',
        'received_from',
        'total',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d',
            'total' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_number', 'received_on', 'money_account_id', 'total', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IncomeVoucherLine::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who the money came from, however the system knows them.
     */
    public function payer(): string
    {
        return $this->customer?->name ?? $this->received_from ?? 'Unnamed payer';
    }
}
