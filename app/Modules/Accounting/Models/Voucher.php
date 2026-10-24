<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\RefusesSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
class Voucher extends Model implements RefusesSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, HasAttachments, LogsActivity;

    public const EVIDENCE_COLLECTION = 'evidence';

    public const INSTRUMENT_TYPES = ['cash', 'cheque', 'bank_transfer', 'mobile', 'card', 'other'];

    protected $fillable = [
        'business_id', 'type', 'status', 'voucher_number', 'voucher_date',
        'money_account_id', 'to_money_account_id', 'counterparty', 'party_type', 'party_id', 'narration',
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

    /** ⭐ Who it was paid to or received from, when that is a supplier, customer or employee (20 Oct 2026). */
    public function party(): MorphTo
    {
        return $this->morphTo();
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

    public function isPendingApproval(): bool
    {
        return $this->status === VoucherStatus::PendingApproval;
    }

    /**
     * A voucher is never approved by the person who prepared it or asked for
     * the approval: the second person is the whole point (finance decision C6).
     */
    public function refusesApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
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

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): Withdraw, by whoever sent it. The
     * request is handed back (the engine's own send-back state, so the same
     * row is reused when it is sent again) and the voucher is a draft again.
     * Nothing was ever posted: a waiting voucher has no number and no journal.
     */
    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string
    {
        return $this->isPendingApproval() ? null : "{$this->title()} is not waiting for approval.";
    }

    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void
    {
        $request->sendBack($by, "Withdrawn by {$by->name}: {$reason->display()}");
        $this->update(['status' => VoucherStatus::Draft]);
    }

    public function withdrawOutcome(): string
    {
        return 'draft';
    }

    public function withdrawLabel(): string
    {
        return "{$this->title()} ({$this->total()->format()})";
    }

    /** What a list and a heading call it: its number, or "Draft" until it has one. */
    public function title(): string
    {
        return $this->voucher_number ?? "Draft {$this->type->label()}";
    }
}
