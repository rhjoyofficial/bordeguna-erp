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

    public const COST_BY_ACQUISITION = 'acquisition_journal';

    public const COST_IN_OPENING_BALANCES = 'opening_balance';

    protected $fillable = [
        'business_id',
        'asset_code',
        'name',
        'account_id',
        'asset_category_id',
        'acquired_on',
        'cost',
        'salvage_value',
        'useful_life_months',
        'depreciation_starts_on',
        'money_account_id',
        'supplier_id',
        'cost_on_books',
        // FEA-003/004 (14 Oct 2026): what the opening trial balance says.
        'opening_accumulated_depreciation',
        'remaining_life_months',
        'opening_as_of',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date:Y-m-d',
            'depreciation_starts_on' => 'date:Y-m-d',
            'cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'useful_life_months' => 'integer',
            'opening_accumulated_depreciation' => 'decimal:4',
            'remaining_life_months' => 'integer',
            'opening_as_of' => 'date:Y-m-d',
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
        // ⚠ FEA-009 (14 Oct 2026): a list that loaded the total up front
        // (`withSum('depreciations', 'amount')`) is read from it; each row used
        // to run this sum up to five times. Anything else still asks the rows.
        $sum = array_key_exists('depreciations_sum_amount', $this->attributes)
            ? $this->attributes['depreciations_sum_amount']
            : $this->depreciations()->sum('amount');

        // ⭐ FEA-004 (owner decision 14 Oct 2026): an opening asset arrives with
        // the depreciation written off before cutover. It is part of what has
        // been written off (so the asset stops at salvage), though no charge
        // row and no journal stand behind it: the opening trial balance does.
        return Money::ofTaka((string) ($sum ?: '0'))->plus($this->openingAccumulated());
    }

    /** Written off before the ERP cutover, per the opening trial balance (FEA-004). */
    public function openingAccumulated(): Money
    {
        return Money::ofTaka((string) ($this->opening_accumulated_depreciation ?? '0'));
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

        // ⭐ FEA-004 (owner decision 14 Oct 2026): an opening asset spreads what
        // was left at cutover (cost less salvage less the opening accumulated
        // depreciation) over the life left at cutover, not its whole cost over
        // its whole life again.
        $straightLine = $this->remaining_life_months !== null
            ? Money::ofMinor((int) round(
                ($this->depreciableAmount()->minor - $this->openingAccumulated()->minor) / max($this->remaining_life_months, 1)
            ))
            : Money::ofMinor((int) round($this->depreciableAmount()->minor / max($this->useful_life_months, 1)));

        // FEA-004: an opening asset's LAST month of remaining life takes what is
        // left too, so a charge rounded down never spills a few paisa into a
        // month after its life has ended.
        $lastMonth = $this->remaining_life_months !== null
            && $this->depreciations()->count() + 1 >= $this->remaining_life_months;

        return $lastMonth || $straightLine->minor >= $remaining->minor ? $remaining : $straightLine;
    }

    /**
     * ⚠⚠ Is the cost in the ledger at all? By its acquisition journal, or by the
     * recorder's word that it sits in the opening balances. An asset whose cost
     * the books never carried must not be written off (plan §2.12, 16 Sep 2026).
     */
    public function costIsOnTheBooks(): bool
    {
        return in_array($this->cost_on_books, [self::COST_BY_ACQUISITION, self::COST_IN_OPENING_BALANCES], true);
    }

    public function isDepreciableIn(int $year, int $month): bool
    {
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        return $this->costIsOnTheBooks()
            && ! $this->isFullyDepreciated()
            && $this->depreciation_starts_on->lessThanOrEqualTo($periodEnd);
    }
}
