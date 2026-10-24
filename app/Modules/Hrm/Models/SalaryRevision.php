<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Hrm\Support\ApprovedForOwnEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An increment proposal (ADJ-06, SAL-05).
 *
 * ADJ-06 ticked "Proposal → Approval → Effective salary revision" and left "HR
 * direct entry" blank, so a raise is a request before it is a fact. The proposed
 * figures live here as data until approval; only then does `SaveSalaryStructure`
 * open the new effective-dated structure. Writing the structure first and
 * correcting it on rejection was never an option — a structure that exists is a
 * structure payroll would pay.
 *
 * SAL-05 permits a retroactive effective date and its recommendation is
 * unambiguous about the consequence: the difference becomes an arrear. So
 * approving a backdated revision recomputes the closed periods *in memory* and
 * emits forward adjustments. No closed payslip is ever rewritten — PAY-12 and
 * WFL-06 both forbid it, and a payslip someone has already been paid against is
 * a record, not a draft.
 */
class SalaryRevision extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    // Rule #22 (14 Oct 2026): approving your own one needs a reason and is marked.
    use Approvable, ApprovedForOwnEmployee, BelongsToBusiness, HasFactory, LogsActivity, WithdrawsBySendingBack;

    protected $fillable = [
        'business_id',
        'employee_id',
        'effective_from',
        // Rule #16, 14 Oct 2026: the mid-cycle date first asked for, when the
        // proposer accepted the next 24th instead. Null when it was a 24th.
        'requested_effective_from',
        'proposed_lines',
        'overtime_eligible',
        'reason',
        'salary_structure_id',
        'arrear_amount',
        'arrear_breakdown',
        'requested_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'requested_effective_from' => 'date:Y-m-d',
            'proposed_lines' => 'array',
            'overtime_eligible' => 'boolean',
            'arrear_amount' => 'decimal:2',
            'arrear_breakdown' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'effective_from', 'requested_effective_from', 'proposed_lines', 'overtime_eligible', 'reason', 'salary_structure_id', 'arrear_amount', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    /**
     * Whether this revision reaches back over months that may already have been
     * paid — the condition SAL-05's arrear rule hangs on.
     */
    public function isRetroactive(): bool
    {
        return Carbon::parse($this->effective_from)->startOfDay()->lt(Carbon::today()->startOfDay());
    }

    /**
     * The proposed gross, for a list that must show what is being asked for
     * before anyone opens it. Fixed lines only — a percentage cannot be resolved
     * without knowing Basic, and the resolver is where that belongs.
     */
    public function proposedTotal(): float
    {
        return round(array_sum(array_map(
            fn (array $line) => (float) ($line['value'] ?? 0),
            $this->proposed_lines ?? [],
        )), 2);
    }

    public function summaryLine(): string
    {
        return sprintf(
            '%s — salary revision effective %s',
            $this->employee?->fullName(),
            Carbon::parse($this->effective_from)->toDateString(),
        );
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return $this->isApplied() ? 'This salary revision is already applied.' : null;
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return 'Salary revision #'.$this->id.($this->employee ? ' for '.$this->employee->fullName() : '');
    }
}
