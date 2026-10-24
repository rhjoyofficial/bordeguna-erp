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
    protected $fillable = ['asset_movement_id', 'asset_item_id', 'quantity', 'condition', 'before'];

    /** The thing's columns a line records before it moves it (wave 3, 24 Oct 2026). */
    public const BEFORE = ['status', 'condition', 'custodian_employee_id', 'custodian_party_id', 'custody_since', 'acknowledged_at', 'office_id', 'warehouse_id', 'area', 'transit_movement_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'condition' => AssetCondition::class, 'before' => 'array'];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(AssetMovement::class, 'asset_movement_id');
    }

    /**
     * ⚠ Trashed things too (corrections wave 3, 24 Oct 2026): a reversed import
     * puts its things in the bin, and their hand-over and its reversal still
     * name them in the custody history.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id')->withTrashed();
    }
}
