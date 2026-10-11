<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Payslip;
use App\Support\Dashboards\WidgetContext;

/**
 * My latest payslip, only once its run is approved (the my-payslips screen's
 * own visibility rule): period, net and whether it has been paid.
 */
class MyPayslipWidget extends EmployeeWidget
{
    public function key(): string
    {
        return 'my_payslip';
    }

    public function title(): string
    {
        return 'My latest payslip';
    }

    public function description(): ?string
    {
        return 'Your most recent approved payslip. Only you (and payroll) can see it.';
    }

    public function refreshSeconds(): int
    {
        return 300;
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function emptyText(): string
    {
        return 'No approved payslip yet.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/my-payslips';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYSLIP_READY];
    }

    public function notifications(): array
    {
        return [['watch' => 'latest_id', 'sound' => 'success', 'message' => 'Your new payslip is ready.']];
    }

    public function data(WidgetContext $context): array
    {
        $me = $this->me($context);

        if ($me === null) {
            return $this->notLinked();
        }

        $payslip = Payslip::with(['run.period', 'payments'])
            ->where('employee_id', $me->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PayrollStatus::Approved, PayrollStatus::Disbursed, PayrollStatus::Locked]))
            ->get()
            ->sortByDesc(fn (Payslip $p) => [$p->run?->period?->year, $p->run?->period?->month])
            ->first();

        if ($payslip === null) {
            return ['kind' => 'empty', 'message' => $this->emptyText(), 'watch' => ['latest_id' => 0]];
        }

        return [
            'kind' => 'stat',
            'unit' => 'money',
            'value' => round((float) $payslip->net, 2),
            'caption' => $payslip->run?->period?->label(),
            'parts' => [
                ['label' => 'Earnings', 'value' => round((float) $payslip->total_earnings, 2), 'unit' => 'money'],
                ['label' => 'Deductions', 'value' => round((float) $payslip->total_deductions, 2), 'unit' => 'money'],
                ['label' => 'Payment', 'value' => $payslip->paymentStatus()->label(), 'unit' => 'text'],
            ],
            'basis' => 'operational',
            'watch' => ['latest_id' => $payslip->id],
        ];
    }
}
