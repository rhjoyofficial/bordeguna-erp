<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One version of what a courier charges, by its own bands, service and weight
 * bracket (courier hub slice 5).
 *
 * ⚠⚠ AN APPROVED CARD NEVER CHANGES ITS PRICES. A new price is a new version
 * with its own effective date (ChatGPT ruling 9), so every estimate keeps
 * meaning what it meant: it carries the version it used. Only a draft's lines
 * can be edited.
 *
 * ⚠ Provider-wide, never business-scoped, like the zone bands.
 */
class CourierRateCard extends Model
{
    use LogsActivity;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const RETIRED = 'retired';

    public const SOURCE_PUBLISHED = 'published';

    public const SOURCE_CALIBRATION = 'calibration';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'provider', 'version', 'effective_from', 'status', 'is_list_price', 'source', 'based_on_id', 'notes',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'is_list_price' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['provider', 'version', 'effective_from', 'status', 'is_list_price', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('couriers');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CourierRateCardLine::class)
            ->orderBy('from_band')->orderBy('to_band')->orderBy('service')->orderBy('weight_from_g');
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * The approved card a provider prices with on a date: the latest effective
     * date on or before it. Null when none is in force yet.
     */
    public static function effectiveFor(string $provider, CarbonInterface|string|null $on = null): ?self
    {
        $date = $on === null ? now('Asia/Dhaka')->toDateString() : (is_string($on) ? $on : $on->toDateString());

        return static::query()
            ->where('provider', (string) $provider)
            ->where('status', self::APPROVED)
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '<=', (string) $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();
    }
}
