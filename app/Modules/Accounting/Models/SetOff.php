<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A set-off (decision D-F, 18 Oct 2026): one firm is both our supplier and
 * our customer, and what we owe it on named bills is cleared against what it
 * owes us on named invoices. SET-2026-27-0001.
 *
 *   pending   raised, waiting in the approval inbox; the lines are only a
 *             proposal and nothing in the books has moved
 *   posted    approved: Dr 2110 AP (supplier) / Cr 1140 AR (customer), the
 *             bills and invoices reduced through a `set_off` party adjustment
 *   rejected  refused by the approver; nothing moved, kept for the record
 *   reversed  undone through the corrections process: the journal reversed,
 *             the adjustment removed, the bills and invoices open again
 *
 * ⚠⚠ NEVER AUTOMATIC. A set-off exists only because a person raised it and
 * another (or the same one, with a written reason, Rule #22) approved it.
 *
 * ⚠ Approval through the voucher workflow (`voucher` key): the same people who
 * give the second approval on a large payment, the Owner or anyone who may
 * post vouchers. Self-approval is allowed with a reason and marked
 * (MarksSelfApproval), so a one-person business is never stuck.
 */
class SetOff extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity, Reversible;

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const REVERSED = 'reversed';

    /** ⭐ Corrections wave 2 (24 Oct 2026): pulled back by its sender before anyone decided. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'business_id', 'number', 'supplier_id', 'customer_id', 'set_off_on', 'amount', 'reason', 'status',
        'party_adjustment_id', 'journal_id', 'created_by', 'posted_by', 'posted_at', 'decision_note',
    ];

    /**
     * ⚠ Mirrors the database default in memory: Reversible also has a
     * `status()` method, and a fresh row without the attribute would send
     * `$setOff->status` looking for a relation by that name.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'set_off_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'set_off_on', 'amount', 'reason', 'status'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('set_off');
    }

    /** ⚠ The voucher workflow, on purpose: see the class note. */
    public function approvalWorkflowKey(): string
    {
        return 'voucher';
    }

    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function selfApprovalPrompt(): string
    {
        return 'You raised this set-off yourself. Approving it is allowed, but write a short reason in the note first; it is kept in the approval history.';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SetOffLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class, 'party_adjustment_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): Withdraw, by whoever raised it. A
     * set-off has no draft, so it is closed as withdrawn (nothing was posted)
     * and a new one is raised if it is still wanted. The request is handed
     * back, not rejected: nobody said no.
     */
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
        return "Set-off {$this->number} ({$this->money()->format()})";
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED && ! $this->isReversed();
    }

    /** The plain word the screens show. */
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
}
