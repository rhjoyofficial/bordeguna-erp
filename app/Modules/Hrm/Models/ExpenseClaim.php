<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An employee asking for money back that they spent on the company.
 *
 * ⚠⚠ THE RECEIPT IS THE POINT. A claim carries its own attachments, and the
 * screens refuse to submit one without at least one file. Joy's requirement was
 * that the office keeps the evidence, and evidence attached later is evidence
 * that often never arrives.
 */
class ExpenseClaim extends Model
{
    use Approvable, BelongsToBusiness, HasAttachments, LogsActivity;

    public const EVIDENCE_COLLECTION = 'evidence';

    public const SETTLE_PAYMENT = 'payment';

    public const SETTLE_PAYROLL = 'payroll';

    protected $fillable = [
        'business_id',
        'employee_id',
        'claim_number',
        'spent_on',
        'amount',
        'purpose',
        'expense_category_id',
        'expense_account_id',
        'settlement_method',
        'settled_at',
        'settled_by',
        'expense_voucher_id',
        'payroll_adjustment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:4',
            'settled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['claim_number', 'spent_on', 'amount', 'settlement_method', 'settled_at', 'expense_account_id'])
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class, 'payroll_adjustment_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * ⚠ Approved means the approval request says so, not that money moved.
     * A claim can sit approved for a week before anyone pays it, and the two
     * states must stay separate or "approved" starts meaning "paid" to whoever
     * reads the screen.
     */
    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    /**
     * What a human should see as the state of this claim.
     */
    public function state(): string
    {
        if ($this->isSettled()) {
            return $this->settlement_method === self::SETTLE_PAYROLL ? 'Added to salary' : 'Paid';
        }

        return match ($this->approvalRequest?->status) {
            ApprovalStatus::Approved => 'Approved, awaiting payment',
            ApprovalStatus::Rejected => 'Rejected',
            default => 'Waiting for approval',
        };
    }

    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->whereNull('settled_at')
            ->whereHas('approvalRequest', fn (Builder $inner) => $inner->where('status', ApprovalStatus::Approved));
    }

    /**
     * The next claim number for this business.
     *
     * ⚠ Derived from the highest existing number rather than a count: a deleted
     * row would otherwise hand the next claim a number already used, and the
     * unique index would refuse it at the worst possible moment.
     */
    public static function nextNumber(int $businessId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->value('claim_number');

        $sequence = $last === null ? 0 : (int) substr((string) $last, strrpos((string) $last, '-') + 1);

        return 'EC-'.str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
    }
}
