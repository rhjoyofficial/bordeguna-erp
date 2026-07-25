<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;
use App\Modules\Hrm\Payroll\Data\PayslipOutcome;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use App\Modules\Hrm\Payroll\PayrollEngine;
use App\Modules\Hrm\Support\PayrollProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gathers, computes and stores — the only place the three meet.
 *
 * The engine stays pure; this gives it inputs and writes the answers down.
 * Lifecycle orchestration lives outside both: this action calculates, it does
 * not decide whether calculating was allowed.
 *
 * Validation runs **before** anything is computed and outside the transaction,
 * because WFL-09 asks for every error to be shown up front and a retry once
 * they are fixed. The calculation itself is then one transaction: WFL-09 ticked
 * "the whole batch fails and rolls back", and a partial payroll is worse than
 * none because somebody will pay from it.
 */
class CalculatePayroll
{
    public function __construct(
        private readonly PayrollContextBuilder $builder,
        private readonly PayrollEngine $engine,
        private readonly ValidatePayrollRun $validator,
        private readonly PayrollProgress $progress,
    ) {}

    public function handle(PayrollPeriod $period, ?PayrollConfig $config = null): PayrollRun
    {
        $config ??= PayrollConfig::defaults();

        $run = $this->openRun($period, $config);
        $employees = $this->validator->population($period);

        $this->progress->start($run, $employees->count());

        // WFL-03 / WFL-09: nothing is calculated while any issue stands.
        $report = $this->validator->handle($period);

        if ($report['issue_count'] > 0) {
            $run->update([
                'validation_report' => $report,
                'is_validated' => false,
                'queue_state' => 'blocked',
            ]);
            $this->progress->fail($run);

            return $run->fresh();
        }

        try {
            return $this->calculate($run, $period, $config, $employees, $report);
        } catch (Throwable $exception) {
            // The transaction has already rolled back; record why so a failed
            // run explains itself rather than sitting silently in Draft.
            $run->update([
                'status' => PayrollStatus::Draft,
                'queue_state' => 'failed',
                'failure_message' => $exception->getMessage(),
            ]);
            $this->progress->fail($run);

            throw $exception;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $report
     */
    private function calculate(PayrollRun $run, PayrollPeriod $period, PayrollConfig $config, $employees, array $report): PayrollRun
    {
        $result = DB::transaction(function () use ($run, $period, $config, $employees) {
            $run->payslips()->each(fn (Payslip $payslip) => $payslip->delete());

            $earnings = 0.0;
            $deductions = 0.0;
            $net = 0.0;
            $blocked = 0;
            $processed = 0;
            $total = $employees->count();

            foreach ($employees as $employee) {
                $context = $this->builder->build($employee, $period, $config);
                $outcome = $this->engine->process($context);

                $this->store($run, $employee, $outcome, $period);

                $earnings += $outcome->totalEarnings;
                $deductions += $outcome->totalDeductions;
                $net += $outcome->net;
                $blocked += $outcome->isBlocked ? 1 : 0;

                // Reported from outside the transaction's visibility, so the UI
                // can watch it move while the batch is still open.
                $this->progress->calculating($run, ++$processed, $total);
            }

            $run->update([
                'status' => PayrollStatus::Calculated,
                'employee_count' => $total,
                'blocked_count' => $blocked,
                'total_earnings' => round($earnings, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($net, 2),
                'calculated_at' => now(),
                'calculated_by' => Auth::id(),
                'queue_state' => 'done',
                'failure_message' => null,
            ]);

            return $run;
        });

        $result->update(['validation_report' => $report, 'is_validated' => true]);
        $this->progress->finish($result, $employees->count());

        return $result->fresh(['payslips.lines', 'period']);
    }

    /**
     * Re-use the current run while WFL-05 still allows a free re-run, clearing
     * its payslips so a recalculation replaces rather than accumulates.
     * Otherwise open the next version (WFL-06).
     */
    private function openRun(PayrollPeriod $period, PayrollConfig $config): PayrollRun
    {
        $latest = $period->latestRun();

        if ($latest !== null && $latest->isRerunnable()) {
            $latest->update(['config' => $config->toArray()]);

            return $latest;
        }

        return $period->runs()->create([
            'version' => ($latest?->version ?? 0) + 1,
            'status' => PayrollStatus::Draft,
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
