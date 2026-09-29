<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What the warehouse already held on the day the books start.
 */
class OpeningStock extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'warehouse_id', 'as_at', 'note', 'created_by'];

    protected function casts(): array
    {
        // ⚠ 'date:Y-m-d', not 'date': serialised as an ISO UTC timestamp, midnight
        // in Dhaka became the previous day on screen (29 Sep 2026).
        return ['as_at' => 'date:Y-m-d'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['warehouse_id', 'as_at'])
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
