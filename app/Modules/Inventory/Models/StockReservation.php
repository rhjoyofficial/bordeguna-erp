<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A slice of one batch held for an approved document until it is handed over
 * or the document is cancelled (Phase 2, 17 Oct 2026).
 *
 * ⚠ Never deleted: `released_at` says the hold ended. A reservation with no
 * release is live and is subtracted from what anybody else may take.
 */
class StockReservation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'stock_batch_id',
        'quantity',
        'reason',
        'reference_type',
        'reference_id',
        'created_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'released_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('stock_reservations.released_at');
    }

    public function scopeFor(Builder $query, string $referenceType, int $referenceId): Builder
    {
        return $query->where('reference_type', $referenceType)->where('reference_id', $referenceId);
    }

    /** How much of one batch is live-held, as a four-place string (the stock quantity domain). */
    public static function heldOn(int $batchId): string
    {
        return static::liveByBatch([$batchId])[$batchId] ?? '0.0000';
    }

    /**
     * Live reserved quantity per batch, in paisa-exact 4dp strings, optionally
     * leaving out one document's own reservations (the one about to consume).
     *
     * @param  array<int, int>  $batchIds
     * @return array<int, string> batch id => quantity
     */
    public static function liveByBatch(array $batchIds, ?string $exceptType = null, ?int $exceptId = null): array
    {
        if ($batchIds === []) {
            return [];
        }

        return static::query()
            ->live()
            ->whereIn('stock_batch_id', $batchIds)
            ->when($exceptType !== null && $exceptId !== null, fn (Builder $q) => $q->where(fn (Builder $own) => $own
                ->where('reference_type', '<>', $exceptType)
                ->orWhere('reference_id', '<>', $exceptId)))
            ->groupBy('stock_batch_id')
            ->selectRaw('stock_batch_id, SUM(quantity) as quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->stock_batch_id => number_format((float) $row->quantity, 4, '.', '')])
            ->all();
    }
}
