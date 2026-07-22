<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named, versioned (by year) holiday calendar (SCH-06/07). Holds the weekly
 * off-days and its dated holidays. The Phase-2b attendance engine treats a day
 * as non-working if it is a weekly off or a holiday on the employee's assigned
 * calendar.
 */
class HolidayCalendar extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

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
}
