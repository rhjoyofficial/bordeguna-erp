<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One figure from Bor de Guna's own manual payroll, for the W2 parallel run.
 *
 * ⚠⚠ **NOTHING IN PAYROLL MAY READ THIS MODEL.** It exists only to be compared
 * against what the system independently produced. The moment a calculated
 * figure derives from the manual sheet, the parallel run stops being evidence
 * and becomes a copy of the thing it was meant to verify.
 */
class ManualPayrollLine extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * How the items a payroll sheet might name map onto one comparable key.
     *
     * ⚠ Aliases rather than a fixed layout, following `AttendanceCsvParser`'s
     * discipline — the real ZKTeco export taught this project that a client's
     * actual column names are never the ones the design assumed.
     *
     * ⚠ Deliberately loose on the earnings side and strict on `net`: net is the
     * one item ROL-03's gate always applies to, so it must never be
     * accidentally matched by something else.
     *
     * @var array<string, array<int, string>>
     */
    public const ITEM_ALIASES = [
        'net' => ['net', 'netpay', 'netsalary', 'netpayable', 'takehome', 'payable'],
        'gross' => ['gross', 'grosssalary', 'grosspay', 'totalearning', 'totalearnings'],
        'basic' => ['basic', 'basicsalary', 'basicpay'],
        'house_rent' => ['houserent', 'hra', 'rent'],
        'medical' => ['medical', 'medicalallowance'],
        'conveyance' => ['conveyance', 'transport', 'transportallowance'],
        'ta_da' => ['tada', 'tada', 'travelallowance', 'dailyallowance', 'ta', 'da'],
        'overtime' => ['overtime', 'ot', 'otamount', 'overtimeamount'],
        'bonus' => ['bonus', 'festivalbonus', 'eidbonus'],
        'absent' => ['absent', 'absentdeduction', 'absencededuction'],
        'late_early' => ['late', 'latededuction', 'lateearly', 'lateearlydeduction'],
        'lwp' => ['lwp', 'leavewithoutpay', 'unpaidleave'],
        'loan_repayment' => ['loan', 'loanrepayment', 'loandeduction', 'advancerecovery', 'advance'],
        'income_tax' => ['tax', 'incometax', 'tds'],
        'provident_fund' => ['pf', 'providentfund'],
        'total_deductions' => ['totaldeduction', 'totaldeductions', 'deduction', 'deductions'],
    ];

    protected $fillable = [
        'business_id',
        'payroll_period_id',
        'employee_id',
        'source_employee_ref',
        'item_label',
        'item_key',
        'amount',
        'source_row',
        'source_file',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'source_row' => 'integer',
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

    /**
     * Resolve a column heading from the client's sheet to a comparable key.
     *
     * Returns a normalised fallback rather than null when nothing matches, so
     * an unrecognised column is **imported and reported as unmatched** instead
     * of being dropped. A silently ignored column is a figure nobody compared.
     */
    public static function keyFor(string $label): string
    {
        $normalised = Str::of($label)->lower()->replaceMatches('/[^a-z0-9]/', '')->value();

        foreach (self::ITEM_ALIASES as $key => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $key;
            }
        }

        return $normalised !== '' ? $normalised : 'unknown';
    }

    public function scopeForPeriod(Builder $query, int $periodId): Builder
    {
        return $query->where('payroll_period_id', $periodId);
    }
}
