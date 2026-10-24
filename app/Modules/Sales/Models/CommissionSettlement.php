<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Settling a commission agreement through a day (Joy's Q18, 25 Oct 2026).
 *
 *   amount = everything earned from the start through `through_date`
 *            less what earlier LIVE settlements already took
 *
 * so a return after a settlement simply lowers the next one, and a reversed
 * settlement's money is earned again by the next.
 *
 *   payable    Dr Sales Commission / Cr Commission Payable (the person)
 *   paid_now   Dr Sales Commission / Cr the money account
 *   payroll    an Addition on the next open payroll (employees only); the
 *              payroll run posts it, so nothing posts here
 *
 * Above the Finance policy limit it waits for a second person (the voucher
 * workflow); self-approval is allowed with a reason (Joy, 10 Sep). Reverse
 * goes through the corrections engine (CommissionSettlementActionPolicy).
 */
class CommissionSettlement extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity, Reversible;

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const METHODS = ['payable', 'paid_now', 'payroll'];

    protected $fillable = [
        'business_id', 'number', 'commission_agreement_id', 'settled_on', 'from_date', 'through_date',
        'sold_quantity', 'sold_value', 'earned_to_date', 'amount', 'method', 'money_account_id',
        'payroll_adjustment_id', 'journal_id', 'status', 'note', 'decision_note', 'created_by',
        'posted_by', 'posted_at',
    ];

    /**
     * ⚠ Mirrors the database default in memory: Reversible has a `status()`
     * method, and a fresh row without the attribute would look for a relation.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'settled_on' => 'date:Y-m-d',
            'from_date' => 'date:Y-m-d',
            'through_date' => 'date:Y-m-d',
            'sold_quantity' => 'decimal:4',
            'sold_value' => 'decimal:4',
            'earned_to_date' => 'decimal:4',
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'settled_on', 'through_date', 'amount', 'method', 'status'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('commission_settlement');
    }

    public function approvalWorkflowKey(): string
    {
        return 'voucher';
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CommissionAgreement::class, 'commission_agreement_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function payrollAdjustment(): BelongsTo
    {
        return $this->belongsTo(PayrollAdjustment::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CommissionPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    /** What is still owed on a payable settlement: its amount less live payments. */
    public function owed(): Money
    {
        if ($this->method !== 'payable' || ! $this->isPosted()) {
            return Money::zero();
        }

        $paid = Money::zero();

        foreach ($this->payments()->live()->get(['amount']) as $payment) {
            $paid = $paid->plus(Money::ofTaka((string) $payment->amount));
        }

        return $this->money()->plus($paid->negated());
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED && ! $this->isReversed();
    }

    /** Pending or posted and not reversed: it takes its share of what was earned. */
    public function isLive(): bool
    {
        return in_array($this->status, [self::PENDING, self::POSTED], true) && ! $this->isReversed();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isReversed() => 'Reversed',
            $this->status === self::POSTED => 'Posted',
            $this->status === self::REJECTED => 'Rejected',
            $this->status === self::WITHDRAWN => 'Withdrawn',
            default => 'Waiting for approval',
        };
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'paid_now' => 'Paid now',
            'payroll' => 'Next payroll',
            default => 'Owed',
        };
    }

    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function selfApprovalPrompt(): string
    {
        return 'You raised this commission settlement yourself. Approving it is allowed, but write a short reason in the note first; it is kept in the approval history.';
    }

    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string
    {
        return $this->isPending() ? null : "{$this->number} is not waiting for approval.";
    }

    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void
    {
        $request->sendBack($by, "Withdrawn by {$by->name}: {$reason->display()}");
        $this->update(['status' => self::WITHDRAWN, 'decision_note' => "Withdrawn: {$reason->display()}"]);
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return "Commission settlement {$this->number} (Tk {$this->money()->format()})";
    }
}
