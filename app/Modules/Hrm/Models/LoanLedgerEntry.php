<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of a loan balance. Append-only, like the leave ledger and the
 * PAY-11 balance ledger before it.
 *
 * `source_key` matters more here than anywhere else in the system. A payroll run
 * may be recalculated freely while it is still Draft or Calculated (WFL-05), and
 * a repayment posted twice would take the same money from an employee twice.
 * Posting through `once()` makes a repeat a no-op, and the unique index on
 * (business_id, source_key) is the guarantee behind it.
 */
class LoanLedgerEntry extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'loan_id',
        'employee_id',
        'entry_type',
        'amount',
        'occurred_on',
        'payroll_period_id',
        'note',
        'reference_type',
        'reference_id',
        'source_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => LoanLedgerEntryType::class,
            'amount' => 'decimal:2',
            'occurred_on' => 'date:Y-m-d',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
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
     * The key a payroll repayment posts under. Keyed on the **installment**
     * rather than the payslip, because a run may be recalculated and each
     * recalculation replaces its payslips — the installment is the thing that
     * must be collected exactly once.
     */
    public static function repaymentKey(int $installmentId): string
    {
        return "loan_installment:{$installmentId}";
    }

    /**
     * Everything an employee still owes across every loan — what a payroll
     * screen shows next to their name.
     */
    public static function outstandingForEmployee(int $employeeId): float
    {
        return round((float) static::where('employee_id', $employeeId)->sum('amount'), 2);
    }
}
