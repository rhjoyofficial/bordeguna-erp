<?php

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\AssetPartyKind;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Somebody outside the business who can hold a thing: a repair shop, a
 * freelancer (slice A2, plan §2.4). A reusable record, so custody history
 * points at one party and not at a name typed differently each time.
 */
class AssetParty extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'name', 'organisation', 'phone', 'kind', 'supplier_id', 'note', 'is_active'];

    protected $attributes = ['kind' => 'other', 'is_active' => true];

    protected function casts(): array
    {
        return ['kind' => AssetPartyKind::class, 'is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'organisation', 'phone', 'kind', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function label(): string
    {
        return $this->organisation ? "{$this->name} ({$this->organisation})" : $this->name;
    }
}
