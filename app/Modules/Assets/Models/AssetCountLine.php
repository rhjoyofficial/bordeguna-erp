<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing on a stock-take: expected or not, and what the counter said.
 */
class AssetCountLine extends Model
{
    protected $fillable = ['asset_count_id', 'asset_item_id', 'expected', 'expected_quantity', 'found', 'found_quantity', 'condition', 'marked_by', 'marked_at', 'note'];

    protected function casts(): array
    {
        return [
            'expected' => 'boolean',
            'expected_quantity' => 'integer',
            'found' => 'boolean',
            'found_quantity' => 'integer',
            'condition' => AssetCondition::class,
            'marked_at' => 'datetime',
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

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function isMarked(): bool
    {
        return $this->found !== null;
    }
}
