<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The work schedule's two group-wide settings (14 Oct 2026): how many
 * seconds an alert stays on screen (3 to 30) and the master switch. One row,
 * id 1; current() makes it if a fresh database lacks it.
 */
class WorkScheduleSetting extends Model
{
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 30;

    protected $fillable = ['alert_seconds', 'is_enabled', 'updated_by'];

    protected function casts(): array
    {
        return [
            'alert_seconds' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->find(1)
            ?? self::query()->forceCreate(['id' => 1, 'alert_seconds' => 8, 'is_enabled' => true]);
    }
}
