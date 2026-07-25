<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A reusable shift definition (SCH-02). Timing is stored as raw configuration;
 * the Phase-2b attendance engine derives late/early/overtime against it. An
 * overnight shift (SCH-03) attributes its punches to the START date, within a
 * configurable cross-midnight window.
 */
class Shift extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'name',
        'start_time',
        'end_time',
        'grace_minutes',
        'break_minutes',
        'break_policy',
        'minimum_payable_hours',
        'late_threshold_minutes',
        'early_leave_threshold_minutes',
        'is_overnight',
        'cross_midnight_window_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'break_minutes' => 'integer',
            'break_policy' => \App\Modules\Hrm\Enums\BreakPolicy::class,
            'minimum_payable_hours' => 'decimal:2',
            'late_threshold_minutes' => 'integer',
            'early_leave_threshold_minutes' => 'integer',
            'cross_midnight_window_minutes' => 'integer',
            'is_overnight' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'start_time', 'end_time', 'grace_minutes', 'break_minutes', 'break_policy',
                'minimum_payable_hours', 'late_threshold_minutes', 'early_leave_threshold_minutes',
                'is_overnight', 'cross_midnight_window_minutes', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Time-only H:i for the UI (the column is a full time cast to a datetime).
     */
    public function timeValue(string $field): ?string
    {
        return $this->{$field} ? \Illuminate\Support\Carbon::parse($this->{$field})->format('H:i') : null;
    }
}
