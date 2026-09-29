<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date on which a calendar's non-working rule is cancelled: the date IS a
 * working day, whatever the weekly-off pattern or a dated holiday says.
 * Tenant scoping is inherited through the calendar, exactly like Holiday.
 *
 * The two readers are DayContextBuilder::calendarContext() (attendance) and
 * LeaveDayCalculator::isWorkingDay() (leave charging) - if a third place ever
 * asks "is this a working day", it must consult this table too.
 */
class WorkingDayOverride extends Model
{
    protected $fillable = [
        'holiday_calendar_id',
        'date',
        'reason',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(HolidayCalendar::class, 'holiday_calendar_id');
    }
}
