<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): one SSL Commerz payout. The gross leaves
 * SSL Commerz Clearing, SSL's charge goes to 6475 Payment Gateway Charge
 * (mapping sales.gateway_fees) and the net lands in the bank, in one balanced
 * journal (RecordSslSettlement). Kept and marked when reversed, never edited
 * or deleted (Reversible; the corrections engine reverses it).
 */
class SslSettlement extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity, Reversible;

    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = [
        'business_id',
        'number',
        'settled_on',
        'clearing_money_account_id',
        'bank_money_account_id',
        'gross',
        'fee',
        'net',
        'fee_account_id',
        'reference',
        'note',
        'journal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settled_on' => 'date:Y-m-d',
            'gross' => 'decimal:2',
            'fee' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    public function clearing(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'clearing_money_account_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'bank_money_account_id');
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fee_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('ssl_settlement');
    }
}
