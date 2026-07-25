<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll calculation and the payslips it produces.
 *
 * The WFL-02 lifecycle beyond Draft/Calculated — review, approval, disbursement
 * and locking — belongs to Phase 3c, so this screen deliberately offers only
 * what it can actually enforce. Every payslip can explain itself, which is the
 * half of PAY-13 that matters most once someone queries their pay.
 */
class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        $year = $request->integer('year') ?: (int) Carbon::today()->year;
        $month = $request->integer('month') ?: (int) Carbon::today()->month;

        $period = PayrollPeriod::where('year', $year)->where('month', $month)->first();
        $run = $period?->latestRun();

        return Inertia::render('hrm/payroll/index', [
            'year' => $year,
            'month' => $month,
            'period' => $period === null ? null : [
                'id' => $period->id,
                'label' => $period->label(),
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'divisor_days' => $period->divisor_days,
            ],
            'run' => $run === null ? null : [
                'id' => $run->id,
                'version' => $run->version,
                'status' => $run->status,
                'employee_count' => $run->employee_count,
                'blocked_count' => $run->blocked_count,
                'total_earnings' => (float) $run->total_earnings,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'calculated_at' => $run->calculated_at?->format('Y-m-d H:i'),
                'calculated_by' => $run->calculator?->name,
                'is_rerunnable' => $run->isRerunnable(),
            ],
            'payslips' => $run === null ? [] : $run->payslips()
                ->with(['employee:id,employee_code,first_name,last_name', 'lines'])
                ->get()
                ->sortBy(fn (Payslip $p) => $p->employee?->fullName())
                ->values()
                ->map(fn (Payslip $payslip) => $this->present($payslip)),
            'periods' => PayrollPeriod::orderByDesc('year')->orderByDesc('month')->limit(12)->get()
                ->map(fn (PayrollPeriod $p) => [
                    'year' => $p->year,
                    'month' => $p->month,
                    'label' => $p->label(),
                ]),
        ]);
    }

    /**
     * WFL-05 permits a free re-run while the payroll is Draft or Calculated.
     * Anything beyond that needs the reopen approval Phase 3c builds, so it is
     * refused here rather than half-supported.
     */
    public function calculate(Request $request, CalculatePayroll $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $period = PayrollPeriod::forMonth($validated['year'], $validated['month']);
        $existing = $period->latestRun();

        if ($existing !== null && ! $existing->isRerunnable()) {
            return back()->with('error', 'This payroll is past Calculated and needs a reopen approval before it can be re-run.');
        }

        $run = $action->handle($period);

        return back()->with('success', sprintf(
            '%s calculated: %d employee(s), net %s.%s',
            $period->label(),
            $run->employee_count,
            number_format((float) $run->total_net, 2),
            $run->blocked_count > 0
                ? sprintf(' %d line(s) blocked and need attention before approval.', $run->blocked_count)
                : '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payslip $payslip): array
    {
        return [
            'id' => $payslip->id,
            'employee' => $payslip->employee?->fullName(),
            'employee_code' => $payslip->employee?->employee_code,
            'gross_salary' => (float) $payslip->gross_salary,
            'total_earnings' => (float) $payslip->total_earnings,
            'total_deductions' => (float) $payslip->total_deductions,
            'net' => (float) $payslip->net,
            'is_blocked' => $payslip->is_blocked,
            'block_reasons' => $payslip->block_reasons ?? [],
            'notes' => $payslip->explanation(),
            'lines' => $payslip->lines->map(fn (PayslipLine $line) => [
                'code' => $line->code,
                'label' => $line->label,
                'type' => $line->type->value,
                'amount' => (float) $line->amount,
                'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                'rate' => $line->rate === null ? null : (float) $line->rate,
                'formula' => $line->formula,
                'source' => $line->source,
            ]),
        ];
    }
}
