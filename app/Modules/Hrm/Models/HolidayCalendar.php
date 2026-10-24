<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named, versioned (by year) holiday calendar (SCH-06/07). Holds the weekly
 * off-days and its dated holidays. The Phase-2b attendance engine treats a day
 * as non-working if it is a weekly off or a holiday on the employee's assigned
 * calendar.
 */
class HolidayCalendar extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, LogsActivity, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'name',
        'year',
        'weekly_off_days',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'weekly_off_days' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'year', 'weekly_off_days', 'is_default', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }

    /**
     * Dated exceptions the OTHER way: dates that ARE working days despite the
     * weekly-off pattern or a dated holiday (a worked Friday, a make-up day).
     */
    public function workingDayOverrides(): HasMany
    {
        return $this->hasMany(WorkingDayOverride::class)->orderBy('date');
    }

    /**
     * The single question both attendance and leave charging ask. An override
     * beats both non-working rules; otherwise weekly off or a dated holiday
     * makes the day non-working.
     */
    public function isWorkingDay(Carbon $date): bool
    {
        if ($this->workingDayOverrides()->whereDate('date', $date->toDateString())->exists()) {
            return true;
        }

        if (in_array($date->dayOfWeek, array_map('intval', $this->weekly_off_days ?? []), true)) {
            return false;
        }

        return ! $this->holidays()->whereDate('date', $date->toDateString())->exists();
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['holiday_calendar_id'];
    }

    public function binLabel(): string
    {
        return 'Calendar '.$this->name.' '.$this->year;
    }

    /** Its own holidays and working days do not make it used; they go and come back with it. */
    public function usageIgnoredTables(): array
    {
        return ['attachments', 'holidays', 'working_day_overrides'];
    }

    public function whyKept(): ?string
    {
        return $this->is_default ? 'The default calendar stays. Make another one the default first.' : null;
    }
}
