<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One amount spread over N months between a balance-sheet head and its P&L
 * head (Module 2, Slice 4). The schedule holds the plan; the journals the
 * release engine posts are the only accounting truth - this row never IS
 * the ledger, it only drives it.
 */
class AmortizationSchedule extends Model
{
    use BelongsToBusiness;

    public const KINDS = ['prepaid_expense', 'accrued_expense', 'deferred_income'];

    protected $fillable = [
        'business_id', 'kind', 'name', 'source_account_id', 'target_account_id',
        'total_amount', 'start_month', 'months', 'released_through', 'note',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_month' => 'date',
            'released_through' => 'date',
            'total_amount' => 'decimal:2',
            'months' => 'integer',
        ];
    }

    public function sourceAccount()
    {
        return $this->belongsTo(Account::class, 'source_account_id');
    }

    public function targetAccount()
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }

    /**
     * The amount for one given month index (0-based). Every month takes the
     * even share; the FINAL month takes the rounding remainder, so the
     * schedule always releases exactly its total - 10,000 over 12 months is
     * eleven 833.33s and one 833.37, never 12 x 833.33 = 9,999.96.
     */
    public function amountForIndex(int $index): float
    {
        $even = floor($this->total_amount / $this->months * 100) / 100;

        if ($index === $this->months - 1) {
            return round($this->total_amount - $even * ($this->months - 1), 2);
        }

        return $even;
    }

    public function monthAt(int $index): Carbon
    {
        return $this->start_month->copy()->startOfMonth()->addMonthsNoOverflow($index);
    }

    /** The 0-based index of the next unreleased month, or null when done. */
    public function nextIndex(): ?int
    {
        if ($this->status !== 'active') {
            return null;
        }

        if ($this->released_through === null) {
            return 0;
        }

        $next = $this->start_month->copy()->startOfMonth()
            ->diffInMonths($this->released_through->copy()->startOfMonth()) + 1;

        return $next >= $this->months ? null : (int) $next;
    }
}
