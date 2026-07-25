<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\EffectiveDated;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Enums\ScheduleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A shift + holiday calendar bound to a group or an employee for a period
 * (SCH-01/06/07). Assignments are never edited into a new shape — a change
 * closes the old row and opens a new one — so "which schedule applied to this
 * person on date X" stays answerable once payroll needs to reproduce it.
 *
 * Every change is activity-logged, which is what SCH-05 asks for on a published
 * roster. Retroactive protection past a payroll lock waits for Phase 3, where
 * the lock itself is defined.
 */
class ScheduleAssignment extends Model
{
    use BelongsToBusiness, EffectiveDated, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'scope_type',
        'scope_id',
        'schedule_type',
        'shift_id',
        'holiday_calendar_id',
        'effective_from',
        'effective_to',
        'is_temporary',
        'restores_assignment_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScheduleScope::class,
            'schedule_type' => ScheduleType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_temporary' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'scope_type', 'scope_id', 'schedule_type', 'shift_id',
                'holiday_calendar_id', 'effective_from', 'effective_to', 'is_temporary', 'note',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rows targeting one scope, e.g. "everything ever assigned to department 7".
     */
    public function scopeForScope(Builder $query, ScheduleScope $scope, ?int $scopeId): Builder
    {
        return $query->where('scope_type', $scope)
            ->when($scope->needsTarget(), fn (Builder $q) => $q->where('scope_id', $scopeId));
    }

    /**
     * A temporary schedule (SCH-08) is one created with an end date — not one
     * that merely acquired an end date when a later assignment closed it.
     */
    public function isTemporary(): bool
    {
        return $this->is_temporary;
    }

    public function isAutoRestored(): bool
    {
        return $this->restores_assignment_id !== null;
    }

    /**
     * "Department: Production" — the scope and its target in one phrase. The
     * business-wide scope has no target worth naming, so it stands alone
     * instead of reading "Whole business: Whole business".
     */
    public function describeTarget(): string
    {
        return $this->scope_type->needsTarget()
            ? $this->scope_type->label().': '.$this->targetName()
            : $this->scope_type->label();
    }

    /**
     * The name of whatever this assignment targets, for lists and audit text.
     */
    public function targetName(): string
    {
        return match ($this->scope_type) {
            ScheduleScope::Business => 'Whole business',
            ScheduleScope::Office => Office::find($this->scope_id)?->name ?? 'Unknown office',
            ScheduleScope::Department => Department::find($this->scope_id)?->name ?? 'Unknown department',
            ScheduleScope::Section => Section::find($this->scope_id)?->name ?? 'Unknown section',
            ScheduleScope::Employee => Employee::find($this->scope_id)?->fullName() ?? 'Unknown employee',
        };
    }
}
