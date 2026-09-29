<?php

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kind of thing the business keeps: IT, studio gear, furniture (plan §2.2).
 *
 * ⚠ The code is part of every tag issued under it (`BDG-IT-0007`), so it is
 * fixed once an item carries it: a tag printed on a sticker never changes.
 */
class AssetCategory extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'default_tracking',
        // Book depreciation life a new fixed asset of this class defaults to (B4).
        'default_useful_life_months',
        'is_active',
        'sort',
    ];

    protected $attributes = [
        'default_tracking' => 'individual',
        'is_active' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'default_tracking' => AssetTracking::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'code', 'default_tracking', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssetItem::class, 'asset_category_id');
    }
}
