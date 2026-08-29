<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named list of people, which is what a send is aimed at.
 *
 * ⚠ Membership is explicit rows, not a saved filter. A filter re-evaluated at
 * send time is a different audience every time it runs, and "who did we message
 * last Eid" then has no answer. When a rule-driven group arrives it will be a
 * second, clearly separate kind, not a quiet change to this one.
 */
class ContactGroup extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'name', 'description', 'is_active'];

    /** ⚠ The database default repeated, for the reason spelled out on Contact. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'description', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_group_members')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
