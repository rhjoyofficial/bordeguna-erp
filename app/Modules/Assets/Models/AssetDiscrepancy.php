<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\DiscrepancyKind;
use App\Modules\Assets\Enums\DiscrepancyResolution;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gap a stock-take found, and how it was settled (slice A3).
 */
class AssetDiscrepancy extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'asset_count_id', 'asset_item_id', 'kind', 'quantity', 'status_before', 'responsible_user_id', 'state', 'resolution', 'resolved_by', 'resolved_at', 'note'];

    protected $attributes = ['state' => 'open'];

    protected function casts(): array
    {
        return [
            'kind' => DiscrepancyKind::class,
            'resolution' => DiscrepancyResolution::class,
            'quantity' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(AssetCount::class, 'asset_count_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }
}
