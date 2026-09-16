<?php

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\AssetCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing on a movement, and how many of it (always 1 for a tagged thing).
 */
class AssetMovementLine extends Model
{
    protected $fillable = ['asset_movement_id', 'asset_item_id', 'quantity', 'condition'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'condition' => AssetCondition::class];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(AssetMovement::class, 'asset_movement_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }
}
