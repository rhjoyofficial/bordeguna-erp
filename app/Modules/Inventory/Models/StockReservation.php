<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A hold on part of one batch for an approved document (Phase 2, 17 Oct
 * 2026). Open while `released_at` is null; kept after release as evidence of
 * which batch the approved price was taken from.
 *
 * ⚠ COORDINATED between builders E and F (see the migration): one model, one
 * table, read by ConsumesFifoStock and every on-hand figure.
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

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('stock_reservations.released_at');
    }

    public function scopeForReference(Builder $query, Model $reference): Builder
    {
        return $query->where('reference_type', $reference->getMorphClass())->where('reference_id', $reference->getKey());
    }

    /**
     * How much of one batch is held by open reservations, as a four-place
     * string (the stock quantity domain).
     */
    public static function heldOn(int $batchId): string
    {
        return number_format((float) static::query()->open()->where('stock_batch_id', $batchId)->sum('quantity'), 4, '.', '');
    }

    /**
     * Open holds per batch for a set of batch ids: batch id => quantity held.
     *
     * @param  iterable<int>  $batchIds
     * @return array<int, string>
     */
    public static function heldByBatch(iterable $batchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($batchIds) ? $batchIds : iterator_to_array($batchIds))));

        if ($ids === []) {
            return [];
        }

        $held = [];

        static::query()->open()
            ->whereIn('stock_batch_id', $ids)
            ->groupBy('stock_batch_id')
            ->selectRaw('stock_batch_id, SUM(quantity) as quantity')
            ->get()
            ->each(function ($row) use (&$held) {
                $held[(int) $row->stock_batch_id] = number_format((float) $row->quantity, 4, '.', '');
            });

        return $held;
    }
}
