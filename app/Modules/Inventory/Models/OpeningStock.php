<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Models\CorrectionLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What the warehouse already held on the day the books start.
 *
 * ⭐ Corrections wave 1 (20 Oct 2026): Reversible. A wrong opening stock is
 * never edited: it is CORRECTED (OpeningStockCorrector), which keeps it marked
 * Corrected and records a successor for the same warehouse and date that
 * names it in `corrects_id`, the difference landing in Opening Balance Equity.
 * `live_warehouse_id` is the warehouse while this is the live one, NULL after;
 * the database keeps exactly one live opening stock per warehouse.
 */
class OpeningStock extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = ['business_id', 'warehouse_id', 'live_warehouse_id', 'as_at', 'note', 'corrects_id', 'created_by'];

    protected static function booted(): void
    {
        // ⚠ Live from the start: the one-live-per-warehouse index reads this
        // column, so a row written without it would escape the rule.
        static::creating(function (OpeningStock $opening) {
            if (! $opening->isReversed() && $opening->live_warehouse_id === null) {
                $opening->live_warehouse_id = $opening->warehouse_id;
            }
        });
    }

    protected function casts(): array
    {
        // ⚠ 'date:Y-m-d', not 'date': serialised as an ISO UTC timestamp, midnight
        // in Dhaka became the previous day on screen (29 Sep 2026).
        return ['as_at' => 'date:Y-m-d'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['warehouse_id', 'as_at', 'reversed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningStockLine::class);
    }

    /** The opening stock this one corrects, when it is a correction. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    /** The link that replaced THIS one, once it has been corrected. */
    public function correctionAsOriginal(): HasOne
    {
        return $this->hasOne(CorrectionLink::class, 'original_id')->where('original_type', $this->getMorphClass());
    }

    /** A short label for lists and links: "Opening stock #3, Savar". */
    public function label(): string
    {
        return "Opening stock #{$this->id}".($this->relationLoaded('warehouse') || $this->warehouse_id ? ', '.$this->warehouse?->name : '');
    }

    /**
     * ⚠ Valued from the MOVEMENTS the document actually created, never from the
     * line's own figures. Same rule as a goods receipt: what went into stock and
     * what went into the ledger are the same number by construction.
     */
    public function totalValue(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, OpeningStockLine $line) => $carry->plus($line->value()),
            Money::zero(),
        );
    }
}
