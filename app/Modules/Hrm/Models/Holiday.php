<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single dated holiday within a HolidayCalendar. Tenant scoping is inherited
 * through the calendar (which is business-scoped).
 */
class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_calendar_id',
        'date',
        'name',
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
