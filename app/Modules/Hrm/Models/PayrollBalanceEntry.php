<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of the PAY-11 carry-forward balance (Correction C8c).
 *
 * Append-only, the same shape as the leave ledger: an employee's outstanding
 * balance is the sum of these rows and never a stored number, so a recovery on
 * a payslip can always be traced back to the month that could not pay for
 * itself.
 *
 * `source_key` gives the idempotency that matters here more than anywhere else
 * in payroll. A run can be recalculated freely under WFL-05, and a carry-forward
 * that posted twice would deduct the same shortfall from an employee twice.
 */
class PayrollBalanceEntry extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'entry_type',
        'amount',
        'payroll_period_id',
        'occurred_on',
        'note',
        'reference_type',
        'reference_id',
        'source_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => PayrollBalanceEntryType::class,
            'amount' => 'decimal:2',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Post at most once for a given key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function once(string $sourceKey, array $attributes): ?self
    {
        if (static::withoutGlobalScopes()->where('source_key', $sourceKey)->exists()) {
            return null;
        }

        return static::create([...$attributes, 'source_key' => $sourceKey]);
    }

    /**
     * What the employee still owes. The sum of a signed ledger, so it needs no
     * reconciliation and cannot drift from the entries that explain it.
     */
    public static function outstandingFor(int $employeeId): float
    {
        return round((float) static::where('employee_id', $employeeId)->sum('amount'), 2);
    }
}
