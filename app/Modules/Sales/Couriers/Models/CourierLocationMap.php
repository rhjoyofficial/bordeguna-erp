<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A place in Bangladesh (BdGeo district, thana, optional area) and the ids one
 * courier knows it by.
 *
 * ⚠⚠ ONLY AN `applied` MAP IS EVER USED. The matcher applies a map on its own
 * only when the names are equal after normalising (`high`); everything else is
 * a suggestion a person approves. A manual or reviewed map is never rewritten
 * by a later automatic run.
 */
class CourierLocationMap extends Model
{
    use LogsActivity;

    public const APPLIED = 'applied';

    public const SUGGESTED = 'suggested';

    public const AUTO_EXACT = 'auto_exact';

    public const AUTO_SUGGESTED = 'auto_suggested';

    public const MANUAL = 'manual';

    public const API_SUGGESTION = 'api_suggestion';

    public const SOURCES = [self::AUTO_EXACT, self::AUTO_SUGGESTED, self::MANUAL, self::API_SUGGESTION];

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /** An empty area column: the map covers the whole thana. */
    public const WHOLE_THANA = '';

    protected $fillable = [
        'provider', 'district', 'thana', 'area',
        'provider_city_id', 'provider_city_name', 'provider_zone_id', 'provider_zone_name',
        'provider_area_id', 'provider_area_name',
        'status', 'source', 'confidence', 'candidates', 'note', 'reviewed_by', 'reviewed_at',
    ];

    protected $attributes = ['area' => self::WHOLE_THANA];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['provider_city_id', 'provider_zone_id', 'provider_area_id', 'status', 'source', 'confidence'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('couriers');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    /** Written by a person, or looked at by one: an automatic run leaves it alone. */
    public function isHumanOwned(): bool
    {
        return $this->reviewed_at !== null || in_array($this->source, [self::MANUAL, self::API_SUGGESTION], true);
    }
}
