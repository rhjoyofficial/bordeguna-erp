<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\PayrollConfig;
use Illuminate\Support\Carbon;

/**
 * What one employee's banked leave is worth, on a date.
 *
 * ⚠⚠ THE RATE IS PAY-02'S, NOT A SECOND OPINION. Joy decided on 2026-08-01
 * that encashment is valued on Gross; the ERP already knows what one day's pay
 * is - `EmployeePayrollContext::dailyRate()` divides the configured base by the
 * configured divisor - and this reuses that convention rather than carrying a
 * copy. `LeaveEntitlement`'s docblock records why: two places computing one
 * rule eventually disagree about the same person, with no error, just two
 * different numbers, and on an encashable balance that disagreement is cash.
 *
 * ⚠ Priced at the CURRENT salary structure. A raise raises the liability for
 * days already banked, which is correct: encashment pays at the rate in force
 * when it is paid, not when the day was earned.
 *
 * ⚠ Only `can_encash` types count (C6a). Casual leave lapsing unpaid is not a
 * liability, and providing for it would invent money.
 */
final class LeaveProvisionValuation
{
    public function __construct(
        private readonly LeaveBalance $balances,
        private readonly PayrollConfig $config,
    ) {}

    public static function make(?PayrollConfig $config = null): self
    {
        return new self(app(LeaveBalance::class), $config ?? PayrollConfig::defaults());
    }

    /**
     * Encashable days for one employee in the leave year of `$on`.
     *
     * ⚠ CLAMPED AT ZERO PER TYPE, not on the total: a negative balance on one
     * type is not a debt the employee owes back, and letting it net against
     * another type's positive balance would understate the liability. Same rule
     * the settlement worksheet already applies.
     */
    public function encashableDays(int $employeeId, Carbon $on): float
    {
        $types = LeaveType::query()->where('can_encash', true)->get(['id']);

        $days = 0.0;

        foreach ($types as $type) {
            $days += max(0.0, round($this->balances->for($employeeId, $type->id, (int) $on->year), 2));
        }

        return round($days, 2);
    }

    /**
     * One day's pay under PAY-02, from the structure in force on `$on`.
     * Null when the employee has no salary structure - unpriceable rather than
     * free, and the caller must say so instead of provisioning zero silently.
     */
    public function dailyRate(int $employeeId, Carbon $on): ?float
    {
        $structure = SalaryStructure::activeFor($employeeId, $on);

        if ($structure === null) {
            return null;
        }

        $breakdown = $structure->breakdown();

        // ⚠⚠ PAY-02's own rule, called - not a second copy of the arithmetic.
        // See PayrollConfig::dailyRateFrom() for why that matters here of all
        // places: this rate prices an encashable balance, so a divergence from
        // payroll is not a rounding curiosity, it is cash.
        if ($this->config->dailyRateBaseFrom($breakdown->basic, $breakdown->gross) <= 0) {
            return null;
        }

        return round($this->config->dailyRateFrom($breakdown->basic, $breakdown->gross), 4);
    }

    /**
     * The liability for one employee: days x rate, to 2dp.
     *
     * @return array{days: float, rate: float|null, amount: float, unpriced: bool}
     */
    public function for(Employee $employee, Carbon $on): array
    {
        $days = $this->encashableDays($employee->id, $on);
        $rate = $this->dailyRate($employee->id, $on);

        if ($days <= 0) {
            return ['days' => 0.0, 'rate' => $rate, 'amount' => 0.0, 'unpriced' => false];
        }

        if ($rate === null) {
            // ⚠ Days with no rate are REPORTED, never valued at zero. A missing
            // structure is an administrative gap, and quietly provisioning
            // nothing would hide a real liability behind a clean-looking run.
            return ['days' => $days, 'rate' => null, 'amount' => 0.0, 'unpriced' => true];
        }

        return [
            'days' => $days,
            'rate' => $rate,
            'amount' => round($days * $rate, 2),
            'unpriced' => false,
        ];
    }
}
