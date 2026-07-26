<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A manual money movement outside the salary structure (ADJ-08, ADJ-09).
 *
 * ADJ-09 ticked all four controls — reason, attachment, approval and effective
 * payroll month — so every one of them is required rather than encouraged. The
 * approval rides on the existing workflow engine; the month is a real foreign
 * key to a payroll period, so an adjustment can never be "sometime soon"; and
 * the attachment is checked at submission, since the Attachment engine stores
 * polymorphically and there is nothing to point at until the row exists.
 *
 * The one exception is an arrear, which SAL-05 generates from an approved
 * salary revision. It has no file to attach because its evidence *is* the
 * revision — a stronger provenance than an uploaded document, and one the
 * system produced rather than accepted on trust.
 */
class PayrollAdjustment extends Model implements ControlsAttachmentAccess
{
    use Approvable, BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'type',
        'amount',
        'payroll_period_id',
        'reason',
        'origin_type',
        'origin_id',
        'source_key',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayrollAdjustmentType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'type', 'amount', 'payroll_period_id', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved));
    }

    /**
     * The adjustments payroll may actually pay.
     *
     * Two routes, and the difference is deliberate. A **manual** adjustment is
     * payable once approved — ADJ-09 requires it. A **generated** arrear has no
     * approval of its own because SAL-05's recommendation calls it automatic:
     * the human decision already happened when the salary revision was approved,
     * and asking for it twice would either be theatre or a way for back-pay to
     * be silently stranded.
     *
     * A generated row is recognisable by carrying a `source_key` and no approval
     * request, which is exactly what `ApplySalaryRevision` produces and what no
     * hand-entered adjustment can be.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereHas('approvalRequest', fn ($a) => $a->where('status', ApprovalStatus::Approved))
            ->orWhere(fn (Builder $generated) => $generated
                ->whereNotNull('source_key')
                ->whereDoesntHave('approvalRequest')));
    }

    /**
     * Create at most once for a generated adjustment. The unique index on
     * (business_id, source_key) is the real guarantee; this keeps a rerun quiet
     * rather than throwing — the same shape `LeaveLedgerEntry::once` uses.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function once(string $sourceKey, array $attributes): ?self
    {
        if (static::withoutGlobalScopes()->where('source_key', $sourceKey)->exists()) {
            return null;
        }

        return static::create([...$attributes, 'source_key' => $sourceKey]);
    }

    /**
     * Evidence on an adjustment is often personal — a medical bill, a
     * disciplinary letter — so it follows the same owner-or-permitted rule as
     * employee documents and correction evidence.
     */
    public function canAccessAttachments(User $user): bool
    {
        return SelfScope::allows($user, $this->employee, 'hrm.payroll.manage');
    }

    public function summaryLine(): string
    {
        return sprintf(
            '%s — %s of %s for %s',
            $this->employee?->fullName(),
            $this->type->label(),
            number_format((float) $this->amount, 2),
            $this->period?->label(),
        );
    }
}
