<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\StockBatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockBatch extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'product_id',
        'warehouse_id',
        'bin_id',
        'batch_number',
        'expiry_date',
        'manufactured_date',
        'mrp',
        'unit_cost',
        'conversion_factor',
        'initial_quantity',
        'purchase_quantity',
        'purchase_unit_id',
        'current_quantity',
        'status',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockBatchStatus::class,
            'expiry_date' => 'date:Y-m-d',
            // C-MFG-13: set by the production run, and by a goods receipt when
            // the supplier's label gives it (optional there). `mrp` is
            // informational label data ONLY - nothing prices off a batch; the
            // selling price resolver reads product_prices.
            'manufactured_date' => 'date:Y-m-d',
            'mrp' => 'decimal:4',
            'received_at' => 'datetime',
            'unit_cost' => 'decimal:4',
            // ⚠⚠ EIGHT places, and COPIED here rather than resolved from
            // `product_unit_conversions` at cost time. A batch that merely
            // pointed at a conversion row would have its history rewritten the
            // day somebody corrected that row - silently, with the trial balance
            // still balancing. C-UOM-4.
            'conversion_factor' => 'decimal:8',
            'initial_quantity' => 'decimal:4',
            'purchase_quantity' => 'decimal:4',
            'current_quantity' => 'decimal:4',
        ];
    }

    /**
     * C-BIN-7. The placement is the ONLY thing about a batch the activity log
     * carries: "who moved this batch to that shelf" needs an answer, while
     * quantity history already lives in stock movements. Batch creation with a
     * null bin is an empty diff and logs nothing.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bin_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** The unit the supplier invoiced, when this batch was converted on receipt. */
    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'purchase_unit_id');
    }

    /** Where in the warehouse this batch physically sits (C-BIN-1), if placed. */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'bin_id');
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
