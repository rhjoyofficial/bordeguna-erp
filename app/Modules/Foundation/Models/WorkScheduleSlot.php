<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One slot of the working day (Settings > Work schedule, 14 Oct 2026): a
 * focus block, a break, a meal, a prayer or the wrap-up. Group-wide.
 *
 * Times are Asia/Dhaka wall-clock times stored as TIME ('HH:MM:SS');
 * weekdays are 0 = Sunday .. 6 = Saturday. The title and subtitle are data
 * the managers typed, shown as text.
 */
class WorkScheduleSlot extends Model
{
    public const KINDS = ['focus', 'break', 'meal', 'prayer', 'wrap'];

    /** Saturday to Thursday: Friday is the day off. */
    public const DEFAULT_WEEKDAYS = [6, 0, 1, 2, 3, 4];

    protected $fillable = [
        'starts_at', 'ends_at', 'title', 'subtitle', 'kind', 'weekdays',
        'is_active', 'sort_order', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('starts_at')->orderBy('sort_order')->orderBy('id');
    }

    /** 'HH:MM', whatever the driver hands back ('09:05:00' or '09:05'). */
    public static function hm(?string $time): string
    {
        return substr((string) $time, 0, 5);
    }

    /** @return list<int> */
    public function days(): array
    {
        return array_values(array_map('intval', (array) $this->weekdays));
    }

    /** @return array<string, mixed> */
    public function toFeed(): array
    {
        return [
            'id' => $this->id,
            'starts_at' => self::hm($this->starts_at),
            'ends_at' => self::hm($this->ends_at),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'kind' => $this->kind,
            'weekdays' => $this->days(),
        ];
    }
}
