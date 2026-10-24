<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One applied things sheet, TI-000001 per business (corrections wave 3, B11,
 * 24 Oct 2026). Written by ApplyAssetItemImport; the things it registered and
 * the hand-overs it recorded carry its id, so the whole sheet can be put
 * right in one step (Reverse import, FINAL-PLAN 3.5: within 7 days, untouched
 * things only).
 *
 * ⚠ Never deleted. A reversed import keeps its row, marked, with the reason.
 */
class AssetImportBatch extends Model
{
    use BelongsToBusiness;

    /** Reverse import, within this many days of the import (FINAL-PLAN 8.1, 10). */
    public const REVERSE_DAYS = 7;

    protected $fillable = ['business_id', 'number', 'created_count', 'updated_count', 'created_by', 'reversed_at', 'reversed_by', 'reversal_reason'];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime', 'created_count' => 'integer', 'updated_count' => 'integer'];
    }

    /** ⚠ Trashed things too: the bin keeps what a reversed import took away. */
    public function items(): HasMany
    {
        return $this->hasMany(AssetItem::class, 'import_batch_id')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class, 'import_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Still inside the 7 days. */
    public function isWithinWindow(): bool
    {
        return $this->created_at !== null && $this->created_at->copy()->addDays(self::REVERSE_DAYS)->isFuture();
    }
}
