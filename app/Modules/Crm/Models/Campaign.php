<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named acquisition effort: "Eid Honey Campaign 2026".
 *
 * ⚠ `code` is ours and frozen; `external_campaign_id` is Meta's and preserved
 * verbatim. Reporting groups by this row, never by the external string (C-CRM-8).
 */
class Campaign extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'lead_source_id', 'brand_id', 'code', 'name',
        'external_campaign_id', 'starts_on', 'ends_on', 'is_active', 'note',
    ];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'lead_source_id', 'brand_id', 'is_active'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /** ⚠ C-CRM-11: nullable. A generic campaign belonging to no brand is legitimate. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
