<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something the business owns and uses rather than sells.
 */
class FixedAsset extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'asset_code',
        'name',
        'account_id',
        'acquired_on',
        'cost',
        'salvage_value',
        'useful_life_months',
        'depreciation_starts_on',
        'money_account_id',
        'supplier_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'depreciation_starts_on' => 'date',
            'cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'useful_life_months' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_code', 'name', 'account_id', 'cost', 'useful_life_months'])
            ->dontSubmitEmptyLogs();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /**
     * What may ever be written off: cost less whatever it is expected to be
     * worth at the end.
     */
    public function depreciableAmount(): Money
    {
        return Money::ofTaka((string) $this->cost)
            ->plus(Money::ofTaka((string) $this->salvage_value)->negated());
    }

    /**
     * ⚠ DERIVED from the charges, never stored. An `accumulated` column would be
     * a second source of truth for a figure the charge rows already answer, and
     * it would drift the first time a charge was written by a path that forgot
     * to update it.
     */
    public function accumulated(): Money
    {
        return Money::ofTaka((string) ($this->depreciations()->sum('amount') ?: '0'));
    }

    public function remainingToDepreciate(): Money
    {
        return $this->depreciableAmount()->plus($this->accumulated()->negated());
    }

    public function isFullyDepreciated(): bool
    {
        return $this->remainingToDepreciate()->minor <= 0;
    }

    /**
     * The straight-line charge for one month.
     *
     * ⚠⚠ THE LAST MONTH TAKES THE REMAINDER. Dividing 100,000 over 36 months
     * gives 2,777.78 with 0.08 left over; charging the rounded figure every
     * month would leave the asset never quite written off, or written off past
     * its salvage value, depending on which way the rounding fell. Whichever
     * charge exhausts the depreciable amount takes exactly what is left.
     */
    public function monthlyCharge(): Money
    {
        $remaining = $this->remainingToDepreciate();

        if ($remaining->minor <= 0) {
            return Money::zero();
        }

        $straightLine = Money::ofMinor(
            (int) round($this->depreciableAmount()->minor / max($this->useful_life_months, 1))
        );

        return $straightLine->minor >= $remaining->minor ? $remaining : $straightLine;
    }

    public function isDepreciableIn(int $year, int $month): bool
    {
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        return ! $this->isFullyDepreciated()
            && $this->depreciation_starts_on->lessThanOrEqualTo($periodEnd);
    }
}
