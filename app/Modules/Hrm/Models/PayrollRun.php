<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Support\PayrollProgress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One calculation attempt at a payroll period, carrying the WFL-02 lifecycle.
 *
 * Approvable, so approval rides on the existing multi-stage workflow engine
 * rather than a second approval mechanism — which is also what makes WFL-07's
 * "visible in both the shared inbox and the HR screen" true for free.
 *
 * Versioned per WFL-06: reopening before disbursement produces v2, v3 … rather
 * than overwriting figures somebody has already reviewed.
 */
class PayrollRun extends Model implements MarksSelfApproval
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'payroll_period_id',
        'version',
        'status',
        'config',
        'validation_report',
        'is_validated',
        'employee_count',
        'blocked_count',
        'total_earnings',
        'total_deductions',
        'total_net',
        'calculated_at',
        'calculated_by',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'disbursed_at',
        'disbursed_by',
        'locked_at',
        'locked_by',
        'closed_reason',
        'reopened_from_id',
        'queue_state',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => PayrollStatus::class,
            'config' => 'array',
            'validation_report' => 'array',
            'is_validated' => 'boolean',
            'employee_count' => 'integer',
            'blocked_count' => 'integer',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'calculated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'version', 'status', 'employee_count', 'blocked_count', 'total_net',
                'calculated_at', 'reviewed_at', 'approved_at', 'disbursed_at', 'locked_at', 'closed_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Approvals for this type route through the seeded `payroll_run` workflow.
     */
    public function approvalWorkflowKey(): string
    {
        return 'payroll_run';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function unlockRequests(): HasMany
    {
        return $this->hasMany(PayrollUnlockRequest::class);
    }

    public function reopenedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reopened_from_id');
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isRerunnable(): bool
    {
        return $this->status->isRerunnable();
    }

    public function hasBlockedLines(): bool
    {
        return $this->blocked_count > 0;
    }

    /**
     * WFL-03: nothing calculates until every validation issue is cleared.
     */
    public function hasValidationIssues(): bool
    {
        return ($this->validation_report['issue_count'] ?? 0) > 0;
    }

    /**
     * Warnings do NOT block, and this must never be folded into the check
     * above (Joy, 2026-08-01). A warning survives calculation on purpose: it is
     * there to be read at review and approval, which is after the figures
     * exist, not instead of them.
     */
    public function hasValidationWarnings(): bool
    {
        return ($this->validation_report['warning_count'] ?? 0) > 0;
    }

    /**
     * Live progress of a queued calculation. Held in the cache rather than the
     * database because WFL-09 wraps the calculation in one transaction — a
     * progress column written inside it would stay invisible until commit,
     * which is precisely when progress stops being useful.
     *
     * @return array{state: string, processed: int, total: int}
     */
    public function progress(): array
    {
        return app(PayrollProgress::class)->for($this);
    }

    /**
     * Rule #22 (approved 14 Oct 2026): approving a payroll that pays the
     * approver their own salary is a self-approval. Allowed (Joy, 10 Sep),
     * but it needs a reason and is marked in the approval history.
     */
    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $this->payslips()
            ->whereHas('employee', fn ($query) => $query->where('user_id', $user->id))
            ->exists();
    }
}
