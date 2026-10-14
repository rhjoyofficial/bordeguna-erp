<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Modules\Hrm\Payroll\PayrollEngine;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Rule #18 (approved 14 Oct 2026): a change to attendance after payroll for
 * that cycle was approved becomes a typed, traceable adjustment on the NEXT
 * open payroll, never a rewrite of the frozen days or of the approved run.
 *
 * How the difference is measured:
 *
 *  1. Only the attendance-driven lines are compared: absence, leave without
 *     pay, the late/early consequence and the per-day attendance allowances.
 *     Net is NOT compared, because it also moves with loans recovered since,
 *     bonuses and staff purchases, none of which an attendance change caused
 *     (the same reasoning as ApplySalaryRevision's arrear).
 *  2. "What it is now" is priced by re-judging this person's existing days in
 *     the cycle INSIDE A SAVEPOINT that is always rolled back, so the frozen
 *     days are never written. Every piece of evidence (punches, corrections,
 *     waivers, leave, movements, the calendar) lives in its own table, so the
 *     re-judged cycle carries every change made so far.
 *  3. "What was approved" is the run's own payslip lines.
 *  4. The adjustment is the difference less every frozen-attendance
 *     adjustment already recorded for this run and person. So it is
 *     cumulative and exact: a change undone later produces the opposite
 *     adjustment, and running it again with nothing new produces nothing.
 *
 * Typed and traceable: an Arrear (owed to the employee) or a Deduction (owed
 * back), its origin is the frozen payroll run, and its source key
 * `frozen_attendance:{run}:{employee}:{n}` numbers every step. Generated rows
 * carry no approval of their own (PayrollAdjustment::scopePayable), like the
 * salary revision arrear: the human decision was the correction, leave or
 * forgiveness that caused it.
 *
 * ⚠ It records an adjustment for payroll only. It never posts to a bank, cash
 * or ledger account: money posting stays manual (owner, 14 Oct 2026).
 *
 * ⚠ Known limit: if the approved run is later reopened as a new version, that
 * version is recalculated from today's evidence while these adjustments stay
 * on the next month; whoever reopens it should withdraw them by hand.
 */
class SettleFrozenAttendance
{
    public const KEY_PREFIX = 'frozen_attendance:';

    private const ATTENDANCE_CODES = ['absent', 'lwp', 'late_early'];

    public function __construct(
        private readonly PayrollContextBuilder $builder,
        private readonly PayrollEngine $engine,
    ) {}

    /**
     * @return array{adjustment: ?PayrollAdjustment, difference: float, target: ?PayrollPeriod}
     */
    public function handle(Employee $employee, PayrollRun $run, ?PayrollConfig $config = null): array
    {
        $config ??= PayrollConfig::defaults();
        $none = ['adjustment' => null, 'difference' => 0.0, 'target' => null];

        $period = $run->period;
        $payslip = $run->payslips()->where('employee_id', $employee->id)->first();

        if ($period === null || $payslip === null) {
            return $none;
        }

        $structure = $payslip->structure ?? $this->builder->structureFor($employee, $period);
        $codes = [
            ...self::ATTENDANCE_CODES,
            ...collect($structure?->breakdown()->lines ?? [])
                ->filter(fn (ResolvedSalaryLine $line) => $line->isPricedByAttendance())
                ->map(fn (ResolvedSalaryLine $line) => $line->code)
                ->values()
                ->all(),
        ];

        $approved = $this->approvedFigure($payslip, $codes);
        $now = $this->currentFigure($employee, $period, $config, $structure, $codes);
        $already = $this->alreadyAdjusted($employee, $run);

        $difference = round($now - $approved - $already, 2);

        if (abs($difference) < 0.01) {
            return $none;
        }

        $target = $this->nextOpenPeriod($period);

        if ($target === null) {
            return $none;
        }

        $sequence = PayrollAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('source_key', 'like', $this->keyFor($employee, $run).'%')
            ->count() + 1;

        $adjustment = PayrollAdjustment::once($this->keyFor($employee, $run).$sequence, [
            'employee_id' => $employee->id,
            'type' => $difference > 0 ? PayrollAdjustmentType::Arrear : PayrollAdjustmentType::Deduction,
            'amount' => abs($difference),
            'payroll_period_id' => $target->id,
            'reason' => sprintf(
                'Attendance for %s changed after that payroll was approved (rule #18). The approved days were kept; the difference is settled here.',
                $period->label(),
            ),
            'origin_type' => $run->getMorphClass(),
            'origin_id' => $run->id,
            'requested_by' => Auth::id(),
        ]);

        return ['adjustment' => $adjustment, 'difference' => $difference, 'target' => $target];
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function approvedFigure(Payslip $payslip, array $codes): float
    {
        return round((float) $payslip->lines()->whereIn('code', $codes)->get()
            ->sum(fn ($line) => (float) $line->amount * $line->type->sign()), 2);
    }

    /**
     * The cycle re-judged from today's evidence and priced, then thrown away.
     *
     * @param  array<int, string>  $codes
     */
    private function currentFigure(Employee $employee, PayrollPeriod $period, PayrollConfig $config, ?SalaryStructure $structure, array $codes): float
    {
        DB::beginTransaction();

        try {
            app(ProcessAttendance::class)->restate($employee, $period->start_date, $period->end_date);

            $outcome = $this->engine->process($this->builder->build($employee, $period, $config, $structure, withStaffPurchase: false));
        } finally {
            DB::rollBack();
        }

        return round(collect($outcome->lines)
            ->filter(fn (PayslipLineData $line) => in_array($line->code, $codes, true))
            ->sum(fn (PayslipLineData $line) => $line->signedAmount()), 2);
    }

    private function alreadyAdjusted(Employee $employee, PayrollRun $run): float
    {
        return round((float) PayrollAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('source_key', 'like', $this->keyFor($employee, $run).'%')
            ->get()
            ->sum(fn (PayrollAdjustment $a) => (float) $a->amount * ($a->type->isEarning() ? 1 : -1)), 2);
    }

    private function keyFor(Employee $employee, PayrollRun $run): string
    {
        return self::KEY_PREFIX."{$run->id}:{$employee->id}:";
    }

    /**
     * The first payroll month after the frozen one that still takes inputs,
     * opened if it does not exist yet (the same walk as ApplySalaryRevision).
     */
    private function nextOpenPeriod(PayrollPeriod $frozen): ?PayrollPeriod
    {
        $month = Carbon::create($frozen->year, $frozen->month, 1);

        for ($i = 0; $i < 24; $i++) {
            $month = $month->copy()->addMonthNoOverflow();
            $period = PayrollPeriod::forMonth((int) $month->year, (int) $month->month);

            if (PayrollInputWindow::accepts($period)) {
                return $period;
            }
        }

        return null;
    }
}
