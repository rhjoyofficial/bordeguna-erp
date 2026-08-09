<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Where a lead came from: Meta Ads, the website, an event, a referral.
 *
 * ⚠⚠ A TABLE, NOT A STRING. This is a permanent reporting axis - "which source
 * produced the most customers" is one of the questions the CRM exists to answer.
 * Free text gives `Meta`, `meta`, `Meta Ads` and `FB` as four sources and nobody
 * notices until a margin report is short. C-CRM-3, and the same argument that
 * made `brands` a table rather than a string on `products`.
 */
class LeadSource extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'code', 'name', 'is_active', 'note'];

    /**
     * ⚠ The database default repeated on the model. A column default only
     * applies once the row is written, so a freshly created instance would have
     * `is_active` NULL and a caller checking `! $source->is_active` would refuse
     * a source the database considers active. This bit the project once already,
     * on Customer.
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['code', 'name', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
