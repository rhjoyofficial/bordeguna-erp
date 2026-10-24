<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Office;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock-take of one place (slice A3). See the migration.
 */
class AssetCount extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'number', 'office_id', 'warehouse_id', 'status', 'open_place_key', 'note', 'started_by', 'closed_by', 'closed_at',
        // Corrections wave 3 (24 Oct 2026): an open count taken back (CancelAssetCount).
        'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetCountLine::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(AssetDiscrepancy::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function placeLabel(): string
    {
        return $this->office?->name ?? $this->warehouse?->name ?? '-';
    }

    /**
     * Is this item's place the counted place?
     */
    public function counts(AssetItem $item): bool
    {
        return ($this->office_id !== null && $item->office_id === $this->office_id)
            || ($this->warehouse_id !== null && $item->warehouse_id === $this->warehouse_id);
    }
}
