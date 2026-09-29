<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceEvery;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What should be done to one tagged thing, and how often (slice A4).
 *
 * ⚠ Written only by SaveMaintenancePlan, and its dates by RecordMaintenance
 * when the work is done.
 */
class AssetMaintenancePlan extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'asset_item_id', 'title', 'instructions', 'every_count', 'every_unit',
        'next_due_on', 'remind_days', 'remind_from', 'assignee_user_id', 'party_id', 'is_active', 'last_done_on', 'created_by',
    ];

    protected $attributes = ['is_active' => true, 'remind_days' => 3];

    protected function casts(): array
    {
        return [
            'every_count' => 'integer',
            'every_unit' => MaintenanceEvery::class,
            'next_due_on' => 'date:Y-m-d',
            'remind_days' => 'integer',
            'remind_from' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'last_done_on' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'every_count', 'every_unit', 'next_due_on', 'remind_days', 'assignee_user_id', 'party_id', 'is_active', 'last_done_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'party_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class, 'asset_maintenance_plan_id');
    }

    /**
     * Active plans inside their reminder window or overdue, on a thing still
     * kept and not lost.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query, ?Carbon $today = null): void
    {
        $query->where('is_active', true)
            ->whereDate('remind_from', '<=', ($today ?? Carbon::today())->toDateString())
            ->whereHas('item', fn (Builder $item) => $item->whereNotIn('status', [AssetStatus::Disposed->value, AssetStatus::Missing->value]));
    }

    public function everyLabel(): string
    {
        return $this->every_unit->label($this->every_count);
    }

    public function whoLabel(): ?string
    {
        return $this->assignee?->name ?? $this->party?->label();
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->next_due_on->lt(($today ?? Carbon::today())->copy()->startOfDay());
    }
}
