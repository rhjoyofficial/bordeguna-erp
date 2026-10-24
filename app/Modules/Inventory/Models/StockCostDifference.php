<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ⭐ The Tk 10,000 sold-stock rule (corrections wave 1 gaps, 24 Oct 2026;
 * FINAL-PLAN 4.3): the cost difference a correction left on goods that were
 * already sold or used, posted TODAY to its own cost-of-sales head.
 *
 * The journal's source, so the corrected receipt, adjustment or run keeps
 * exactly the journals it had (its reversal stays a plain mirror, and its own
 * "live journal" lookups never see this one). Never edited, never deleted:
 * written once by PostStockCostDifference inside the correction's transaction.
 *
 * `amount` is signed: positive = cost of sales goes DOWN, negative = UP.
 */
class StockCostDifference extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'source_type',
        'source_id',
        'source_number',
        'posted_on',
        'quantity_used',
        'amount',
        'journal_id',
        'note',
        'created_by',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'posted_on' => 'date',
            'quantity_used' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }
}
