<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A requested adjustment to one employee-day's attendance (COR-01/03/04).
 *
 * Approvable, so routing rides on the existing multi-stage workflow engine
 * rather than a bespoke status column. Attachments are optional evidence
 * (COR-04) and access to them follows the same owner-or-HR rule as employee
 * documents — a correction can carry a medical note.
 */
class AttendanceCorrection extends Model implements ControlsAttachmentAccess
{
    use Approvable, BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'attendance_date',
        'type',
        'punch_id',
        'corrected_at',
        'direction',
        'reason',
        'override_reason',
        'requested_by',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'type' => CorrectionType::class,
            'direction' => PunchDirection::class,
            'corrected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_id', 'attendance_date', 'type', 'punch_id',
                'corrected_at', 'direction', 'reason', 'override_reason', 'applied_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function punch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'punch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * Only an approved, applied correction changes what the engine sees.
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    public function scopeForDay(Builder $query, int $employeeId, Carbon|string $date): Builder
    {
        return $query->where('employee_id', $employeeId)
            ->whereDate('attendance_date', Carbon::parse($date)->toDateString());
    }

    public function isPending(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Pending;
    }

    /**
     * Evidence attached to a correction can be personal (a medical note), so
     * it follows the same owner-or-permitted rule as employee documents.
     */
    public function canAccessAttachments(User $user): bool
    {
        return SelfScope::allows($user, $this->employee, 'hrm.attendance.manage');
    }

    public function summaryLine(): string
    {
        $date = Carbon::parse($this->attendance_date)->toDateString();
        $time = $this->corrected_at?->format('H:i');

        return match ($this->type) {
            CorrectionType::AddPunch => sprintf(
                '%s — add a %s punch at %s on %s',
                $this->employee?->fullName(),
                $this->direction?->label() ?? 'missing',
                $time,
                $date,
            ),
            CorrectionType::AmendPunch => sprintf(
                '%s — change the %s punch to %s on %s',
                $this->employee?->fullName(),
                $this->punch?->punched_at->format('H:i') ?? 'existing',
                $time,
                $date,
            ),
            CorrectionType::IgnorePunch => sprintf(
                '%s — disregard the %s punch on %s',
                $this->employee?->fullName(),
                $this->punch?->punched_at->format('H:i') ?? 'selected',
                $date,
            ),
        };
    }
}
