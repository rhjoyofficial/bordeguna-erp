<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Modules\Hrm\Payroll\PayrollEngine;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved increment into effect (ADJ-06, SAL-05).
 *
 * Two things happen, and the second is the whole reason SAL-05 was worth reading
 * twice.
 *
 * **The structure opens.** `SaveSalaryStructure` closes the current row and
 * opens a new one from the effective date — P1's close-old-open-new, so what
 * someone was paid in a month that has already closed stays exactly as it was.
 *
 * **A retroactive date produces an arrear.** SAL-05's recommendation is explicit
 * ("retroactive হলে automatic arrear") and PAY-12 and WFL-06 are equally explicit
 * that an approved payroll is never silently recalculated. Both are satisfied the
 * same way: each already-paid month is re-priced **in memory only**, and the
 * difference travels forward as a visible adjustment on the next open payroll.
 * No closed payslip is touched. A payslip somebody has already been paid against
 * is a record, not a draft.
 *
 * The difference is measured as *new structure minus old structure over the same
 * month*, both computed now, rather than as new-net minus stored-net. That
 * matters: the stored net also reflects that month's bonuses, recoveries and
 * adjustments, and comparing against it would fold every unrelated movement into
 * the arrear. Pricing the same month twice and differencing leaves only what the
 * salary change actually caused.
 */
class ApplySalaryRevision
{
    public function __construct(
        private readonly SaveSalaryStructure $saveStructure,
        private readonly PayrollContextBuilder $builder,
        private readonly PayrollEngine $engine,
    ) {}

    public function handle(SalaryRevision $revision, ?PayrollConfig $config = null): SalaryRevision
    {
        $config ??= PayrollConfig::defaults();

        if ($revision->isApplied()) {
            return $revision;
        }

        return DB::transaction(function () use ($revision, $config) {
            $structure = $this->saveStructure->handle(
                $revision->employee,
                Carbon::parse($revision->effective_from)->toDateString(),
                $revision->proposed_lines ?? [],
                $revision->reason,
                (bool) $revision->overtime_eligible,
            );

            $arrears = $this->arrears($revision, $config);

            $revision->update([
                'salary_structure_id' => $structure->id,
                'arrear_amount' => $arrears['total'],
                'arrear_breakdown' => $arrears['rows'],
                'applied_at' => now(),
            ]);

            if (abs($arrears['total']) >= 0.01) {
                $this->postArrear($revision, $arrears);
            }

            return $revision->fresh();
        });
    }

    /**
     * Re-price every already-paid month the revision reaches back over.
     *
     * Only **disbursed and locked** runs qualify. Anything earlier in the
     * lifecycle can simply be recalculated — WFL-05 allows it while a run is
     * still open, and WFL-06 turns a reviewed or approved run into a new version
     * rather than an arrear. The forward correction exists for money that has
     * already left the building.
     *
     * @return array{total: float, rows: array<int, array<string, mixed>>}
     */
    private function arrears(SalaryRevision $revision, PayrollConfig $config): array
    {
        $employee = $revision->employee;
        $from = Carbon::parse($revision->effective_from)->startOfDay();

        $runs = PayrollRun::with('period')
            ->whereIn('status', [PayrollStatus::Disbursed, PayrollStatus::Locked])
            ->whereHas('period', fn ($q) => $q->whereDate('end_date', '>=', $from->toDateString()))
            ->get()
            ->sortBy(fn (PayrollRun $run) => $run->period->start_date->toDateString());

        $rows = [];
        $total = 0.0;

        foreach ($runs as $run) {
            $payslip = $run->payslips()->where('employee_id', $employee->id)->first();

            if ($payslip === null) {
                continue;
            }

            $delta = $this->deltaFor($payslip, $run->period, $config);

            if (abs($delta) < 0.01) {
                continue;
            }

            $total += $delta;
            $rows[] = [
                'period' => $run->period->label(),
                'payroll_period_id' => $run->period->id,
                'payroll_run_id' => $run->id,
                'paid_net' => (float) $payslip->net,
                'difference' => $delta,
            ];
        }

        return ['total' => round($total, 2), 'rows' => $rows];
    }

    /**
     * What one closed month would have been worth under the new structure, less
     * what it was worth under the old one. Both priced now, with identical
     * non-salary inputs, so everything except the salary change cancels.
     *
     * The old structure comes from the payslip itself rather than from a
     * date lookup — it is the exact structure that was paid, which after the
     * close-old-open-new above is no longer the one a lookup would return.
     */
    private function deltaFor(Payslip $payslip, PayrollPeriod $period, PayrollConfig $config): float
    {
        $employee = $payslip->employee;
        $previous = $payslip->structure;

        if ($employee === null || $previous === null) {
            return 0.0;
        }

        $revised = SalaryStructure::activeFor($employee->id, $period->end_date);

        if ($revised === null || $revised->id === $previous->id) {
            // The revision starts after this month, so it changes nothing here.
            return 0.0;
        }

        // B8: without the capped staff purchase recovery, which is a share of
        // the net and would otherwise shave the arrear by the same share.
        $before = $this->engine->process($this->builder->build($employee, $period, $config, $previous, withStaffPurchase: false));
        $after = $this->engine->process($this->builder->build($employee, $period, $config, $revised, withStaffPurchase: false));

        return round($after->net - $before->net, 2);
    }

    /**
     * The forward correction. Keyed on the revision, so re-applying — or a
     * retried job — never pays the same back-pay twice.
     *
     * A downward revision produces a Recovery rather than an Arrear: ADJ-08
     * named six types precisely so that money moving back to the company is not
     * filed as money owed to the employee.
     *
     * It carries no approval request of its own. SAL-05 calls the arrear
     * automatic, and the human decision has already been taken on the revision
     * this is generated from; `PayrollAdjustment::scopePayable` recognises a
     * generated row by exactly that shape.
     *
     * @param  array{total: float, rows: array<int, array<string, mixed>>}  $arrears
     */
    private function postArrear(SalaryRevision $revision, array $arrears): void
    {
        $target = $this->nextOpenPeriod($arrears['rows']);

        if ($target === null) {
            return;
        }

        $isBackPay = $arrears['total'] > 0;

        PayrollAdjustment::once("salary_revision:{$revision->id}", [
            'employee_id' => $revision->employee_id,
            'type' => $isBackPay ? PayrollAdjustmentType::Arrear : PayrollAdjustmentType::Recovery,
            'amount' => round(abs($arrears['total']), 2),
            'payroll_period_id' => $target->id,
            'reason' => sprintf(
                'Salary revision effective %s, applied to %d already-paid month(s) (SAL-05).',
                Carbon::parse($revision->effective_from)->toDateString(),
                count($arrears['rows']),
            ),
            'origin_type' => $revision->getMorphClass(),
            'origin_id' => $revision->id,
            'requested_by' => Auth::id(),
        ]);
    }

    /**
     * Where the arrear lands: the earliest payroll month after the last one it
     * re-priced that still takes inputs. A month with no period yet is opened
     * for it - an arrear with nowhere to go would simply be lost.
     *
     * ⚠⚠ "Takes inputs" is PayrollInputWindow, not `isOpen()`. isOpen() also
     * counted Reviewed and Approved months, and an arrear posted to one of
     * those was never paid: nothing recalculates them. The old fallback also
     * reopened the month after the last re-priced one with `forMonth`, which
     * returns that month even when it is itself closed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function nextOpenPeriod(array $rows): ?PayrollPeriod
    {
        $lastRepriced = PayrollPeriod::find($rows[count($rows) - 1]['payroll_period_id'] ?? null);

        if ($lastRepriced === null) {
            return null;
        }

        $month = Carbon::create($lastRepriced->year, $lastRepriced->month, 1);

        // Bounded: two years of closed months in a row is not a real state.
        for ($i = 0; $i < 24; $i++) {
            $month = $month->copy()->addMonth();
            $period = PayrollPeriod::forMonth((int) $month->year, (int) $month->month);

            if (PayrollInputWindow::accepts($period)) {
                return $period;
            }
        }

        return null;
    }
}
