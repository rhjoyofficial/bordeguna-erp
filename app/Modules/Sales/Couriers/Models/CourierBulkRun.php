<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One press of "Create challans and book": the orders chosen, the courier, the
 * warehouse, and how far the queued job has gone. Each order's own answer is a
 * CourierBulkRunItem.
 */
class CourierBulkRun extends Model
{
    use BelongsToBusiness;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    protected $fillable = [
        'business_id', 'courier_account_id', 'warehouse_id', 'status', 'total', 'done', 'booked', 'refused',
        'unknown', 'fix_address', 'error', 'started_at', 'finished_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total' => 'integer',
            'done' => 'integer',
            'booked' => 'integer',
            'refused' => 'integer',
            'unknown' => 'integer',
            'fix_address' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CourierBulkRunItem::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::FINISHED, self::FAILED], true);
    }

    /** Recount the totals from the items, so a retried job never counts twice. */
    public function recount(): void
    {
        $counts = CourierBulkRunItem::query()->withoutGlobalScopes()->where('courier_bulk_run_id', $this->id)
            ->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $answered = [CourierBulkRunItem::BOOKED, CourierBulkRunItem::REFUSED, CourierBulkRunItem::UNKNOWN, CourierBulkRunItem::FIX_ADDRESS];

        $this->forceFill([
            'done' => (int) collect($answered)->sum(fn (string $status) => (int) ($counts[$status] ?? 0)),
            'booked' => (int) ($counts[CourierBulkRunItem::BOOKED] ?? 0),
            'refused' => (int) ($counts[CourierBulkRunItem::REFUSED] ?? 0),
            'unknown' => (int) ($counts[CourierBulkRunItem::UNKNOWN] ?? 0),
            'fix_address' => (int) ($counts[CourierBulkRunItem::FIX_ADDRESS] ?? 0),
        ])->save();
    }
}
