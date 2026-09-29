<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A payment, receive, contra or journal voucher.
 *
 * ⚠⚠ A DRAFT IS A NOTE; A POSTED VOUCHER IS A RECORD. Everything about a draft
 * may change or vanish. Once posted it has a number and a journal, and nothing
 * on it changes again - a mistake is reversed (an ordinary reversing journal)
 * and entered afresh, which "Reverse and copy" does in one step.
 */
class Voucher extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    public const EVIDENCE_COLLECTION = 'evidence';

    public const INSTRUMENT_TYPES = ['cash', 'cheque', 'bank_transfer', 'mobile', 'card', 'other'];

    protected $fillable = [
        'business_id', 'type', 'status', 'voucher_number', 'voucher_date',
        'money_account_id', 'to_money_account_id', 'counterparty', 'narration',
        'instrument_type', 'instrument_number', 'instrument_date', 'instrument_bank',
        'total_minor', 'journal_id', 'reversal_journal_id', 'reversal_reason', 'reversed_by', 'reversed_at',
        'copied_from_id', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'status' => VoucherStatus::class,
            'voucher_date' => 'date:Y-m-d',
            'instrument_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'status', 'voucher_number', 'voucher_date', 'money_account_id', 'to_money_account_id', 'total_minor', 'reversal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class)->orderBy('line_no');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function toMoneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(self::class, 'copied_from_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isDraft(): bool
    {
        return $this->status === VoucherStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === VoucherStatus::Posted;
    }

    public function total(): Money
    {
        return Money::ofMinor((int) $this->total_minor);
    }

    /** What a list and a heading call it: its number, or "Draft" until it has one. */
    public function title(): string
    {
        return $this->voucher_number ?? "Draft {$this->type->label()}";
    }
}
