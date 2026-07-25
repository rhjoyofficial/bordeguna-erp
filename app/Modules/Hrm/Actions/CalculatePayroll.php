<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;
use App\Modules\Hrm\Payroll\Data\PayslipOutcome;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Modules\Hrm\Payroll\PayrollEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Gathers, computes and stores — the only place the three meet.
 *
 * The engine stays pure; this gives it inputs and writes the answers down. The
 * whole run happens inside one transaction because WFL-09 ticked "the whole
 * batch fails and rolls back": a partial payroll is worse than none, since
 * somebody will pay from it.
 *
 * Re-running while a payroll is still Draft or Calculated replaces its
 * payslips, which WFL-05 permits freely. The reopen-approval path for an
 * approved run, and the queued execution WFL-04 asks for, are Phase 3c.
 */
class CalculatePayroll
{
    public function __construct(
        private readonly PayrollContextBuilder $builder,
        private readonly PayrollEngine $engine,
    ) {}

    public function handle(PayrollPeriod $period, ?PayrollConfig $config = null): PayrollRun
    {
        $config ??= PayrollConfig::defaults();

        $employees = Employee::whereHas('assignments', fn ($query) => $query
            ->activeOn($period->end_date)
            ->where('is_primary', true))
            ->orderBy('first_name')->orderBy('last_name')->get();

        return DB::transaction(function () use ($period, $config, $employees) {
            $run = $this->openRun($period, $config);

            $earnings = 0.0;
            $deductions = 0.0;
            $net = 0.0;
            $blocked = 0;

            foreach ($employees as $employee) {
                $context = $this->builder->build($employee, $period, $config);
                $outcome = $this->engine->process($context);

                $this->store($run, $employee, $outcome, $period);

                $earnings += $outcome->totalEarnings;
                $deductions += $outcome->totalDeductions;
                $net += $outcome->net;
                $blocked += $outcome->isBlocked ? 1 : 0;
            }

            $run->update([
                'status' => PayrollRun::STATUS_CALCULATED,
                'employee_count' => $employees->count(),
                'blocked_count' => $blocked,
                'total_earnings' => round($earnings, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($net, 2),
                'calculated_at' => now(),
                'calculated_by' => Auth::id(),
            ]);

            return $run->fresh(['payslips.lines', 'period']);
        });
    }

    /**
     * Re-use the current run while it is still re-runnable (WFL-05), clearing
     * its payslips first so a recalculation replaces rather than accumulates.
     * Otherwise open the next version (WFL-06).
     */
    private function openRun(PayrollPeriod $period, PayrollConfig $config): PayrollRun
    {
        $latest = $period->latestRun();

        if ($latest !== null && $latest->isRerunnable()) {
            $latest->payslips()->each(fn (Payslip $payslip) => $payslip->delete());
            $latest->update(['config' => $config->toArray()]);

            return $latest;
        }

        return $period->runs()->create([
            'version' => ($latest?->version ?? 0) + 1,
            'status' => PayrollRun::STATUS_DRAFT,
            'config' => $config->toArray(),
        ]);
    }

    private function store(PayrollRun $run, Employee $employee, PayslipOutcome $outcome, PayrollPeriod $period): void
    {
        $payslip = $run->payslips()->create([
            'employee_id' => $employee->id,
            'gross_salary' => $outcome->grossSalary,
            'total_earnings' => $outcome->totalEarnings,
            'total_deductions' => $outcome->totalDeductions,
            'net' => $outcome->net,
            'is_blocked' => $outcome->isBlocked,
            'block_reasons' => $outcome->blockReasons,
            // The engine's own derivation, stored rather than re-derivable —
            // after a revision, recomputing would answer for today's rules.
            'calculation' => $outcome->toArray(),
            'salary_structure_id' => $this->builder->structureFor($employee, $period)?->id,
        ]);

        foreach ($outcome->lines as $index => $line) {
            $payslip->lines()->create([
                ...$this->lineAttributes($line),
                'sequence' => $index,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lineAttributes(PayslipLineData $line): array
    {
        return [
            'code' => $line->code,
            'label' => $line->label,
            'type' => $line->type,
            'amount' => $line->amount,
            'quantity' => $line->quantity,
            'rate' => $line->rate,
            'formula' => $line->formula,
            'source' => $line->source,
        ];
    }
}
