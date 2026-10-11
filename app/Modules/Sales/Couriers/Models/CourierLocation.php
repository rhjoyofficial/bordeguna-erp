<?php

namespace App\Modules\Sales\Couriers\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a courier's own coverage list: a city, a zone or an area, as the
 * courier names and numbers it. Synced read-only; provider-wide.
 *
 * ⚠ A row the courier stopped listing is kept with `removed_at`, never deleted,
 * so a map that points at it fails loudly instead of vanishing.
 */
class CourierLocation extends Model
{
    public const CITY = 'city';

    public const ZONE = 'zone';

    public const AREA = 'area';

    public const LEVELS = [self::CITY, self::ZONE, self::AREA];

    protected $fillable = [
        'provider', 'level', 'provider_location_id', 'parent_provider_id', 'name', 'extra',
        'synced_at', 'children_synced_at', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
            'synced_at' => 'datetime',
            'children_synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public static function lookup(string $provider, string $level, ?string $id): ?self
    {
        if ($id === null || $id === '') {
            return null;
        }

        return static::query()->where('provider', $provider)->where('level', $level)->where('provider_location_id', $id)->first();
    }
}
