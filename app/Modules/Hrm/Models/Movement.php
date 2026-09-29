<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\MovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Manager-approved presence outside the normal pattern — field duty and
 * official work (POL-10), or work on a holiday/off-day (POL-09).
 *
 * Kept as a separate workflow from leave, per LEV-04: a part-day absence for an
 * errand is a movement, not a fraction of a leave day, and it draws on no
 * balance.
 */
class Movement extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'type',
        'movement_date',
        'from_time',
        'to_time',
        'place',
        'reason',
        'requested_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'movement_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'type', 'movement_date', 'from_time', 'to_time', 'place', 'reason', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('movement_date', Carbon::parse($date)->toDateString());
    }

    public function summaryLine(): string
    {
        $window = $this->from_time && $this->to_time
            ? sprintf(' %s–%s', substr((string) $this->from_time, 0, 5), substr((string) $this->to_time, 0, 5))
            : '';

        return sprintf(
            '%s — %s on %s%s%s',
            $this->employee?->fullName(),
            $this->type->label(),
            Carbon::parse($this->movement_date)->toDateString(),
            $window,
            $this->place ? ' at '.$this->place : '',
        );
    }
}
