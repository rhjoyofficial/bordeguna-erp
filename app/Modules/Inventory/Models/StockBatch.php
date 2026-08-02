<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\StockBatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockBatch extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'product_id',
        'warehouse_id',
        'batch_number',
        'expiry_date',
        'unit_cost',
        'initial_quantity',
        'current_quantity',
        'status',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockBatchStatus::class,
            'expiry_date' => 'date',
            'received_at' => 'datetime',
            'unit_cost' => 'decimal:4',
            'initial_quantity' => 'decimal:4',
            'current_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /**
     * Oldest-first (FIFO): earliest expiry, falling back to earliest received,
     * for stock consumption (usage/adjustment-decrease/transfer-out).
     *
     * ⚠⚠ `id` is the floor and it is not decoration (I5). `created_at` is a
     * second-precision timestamp, so two batches of the same product created
     * within one second tie - which is ordinary, since a single goods receipt
     * carrying the same product on two lines does exactly that. A tie here is
     * resolved by whatever the database feels like returning, and it decides
     * which cost layer is consumed, which is cost of goods sold.
     *
     * ⚠ An ordering that decides an outcome must be TOTAL. This is the same
     * lesson as W2 in Business Switching, where a display string was choosing
     * which tenant somebody saw. There it was a name; here it is a coin flip
     * choosing a price.
     *
     * ⚠ Only a structural test can hold this. SQLite returns tied rows in rowid
     * order, so a behavioural test passes against the broken version too. See
     * FifoOrderingTest.
     */
    public function scopeConsumable(Builder $query): Builder
    {
        // ⚠ Every ordering column is qualified. This scope is applied to plain
        // batch queries today, but an unqualified `id` or `created_at` becomes
        // ambiguous SQL the moment somebody joins to it - the same trap the
        // `business_user` pivot sprang in W2.
        return $query->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            // ⚠⚠ C-INV-3. Expired stock is never consumable. Filtered by DATE
            // rather than by status, and that is the whole point: the `Expired`
            // status case existed and NOTHING in the codebase ever set it, so
            // the scope filtered on `active` and happily sold goods that expired
            // last year while the enum made it look handled. A date comparison
            // is true whether or not any sweep has run.
            //
            // ⚠ `whereDate` and `>=`, so a batch is good through the WHOLE of
            // its expiry date. An off-by-one here destroys a day of sellable
            // stock on every batch.
            ->where(fn ($q) => $q->whereNull('stock_batches.expiry_date')
                ->orWhereDate('stock_batches.expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('stock_batches.expiry_date IS NULL, stock_batches.expiry_date asc')
            ->orderBy('stock_batches.received_at')
            ->orderBy('stock_batches.id');
    }
}
