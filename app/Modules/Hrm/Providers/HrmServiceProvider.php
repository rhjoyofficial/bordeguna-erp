<?php

namespace App\Modules\Hrm\Providers;

use App\Modules\Hrm\Console\Commands\RunLeaveYearEnd;
use App\Modules\Hrm\Reporting\Definitions\AbsenceAndLeaveReport;
use App\Modules\Hrm\Reporting\Definitions\AttendanceExceptionReport;
use App\Modules\Hrm\Reporting\Definitions\CostAllocationReport;
use App\Modules\Hrm\Reporting\Definitions\DailyAttendanceReport;
use App\Modules\Hrm\Reporting\Definitions\EarningsDeductionsBreakdownReport;
use App\Modules\Hrm\Reporting\Definitions\ImportExceptionReport;
use App\Modules\Hrm\Reporting\Definitions\LoanBalanceReport;
use App\Modules\Hrm\Reporting\Definitions\MonthlyAttendanceRegisterReport;
use App\Modules\Hrm\Reporting\Definitions\OrganisationSummaryReport;
use App\Modules\Hrm\Reporting\Definitions\OvertimeReport;
use App\Modules\Hrm\Reporting\Definitions\PayrollLineDetailReport;
use App\Modules\Hrm\Reporting\Definitions\PayrollVarianceReport;
use App\Modules\Hrm\Reporting\Definitions\SalarySheetReport;
use App\Modules\Hrm\Reporting\Definitions\SettlementWorksheetReport;
use App\Modules\Hrm\Reporting\Definitions\UnpaidSalaryReport;
use App\Modules\Hrm\Reporting\ReportRegistry;
use Illuminate\Support\ServiceProvider;

class HrmServiceProvider extends ServiceProvider
{
    /**
     * Every report the module offers (W3).
     *
     * One list, in the order they appear on the reports screen. Adding a report
     * is a class plus a line here — that is the whole promise of the W3
     * framework, and the thing to check has stayed true by the time this list
     * reaches fifteen.
     */
    private const REPORTS = [
        // RPT-01 — attendance.
        DailyAttendanceReport::class,
        MonthlyAttendanceRegisterReport::class,
        AttendanceExceptionReport::class,
        AbsenceAndLeaveReport::class,
        OvertimeReport::class,
        ImportExceptionReport::class,
        OrganisationSummaryReport::class,

        // RPT-02 — payroll. #9 (bank advice) is absent on purpose: Correction
        // C9d blocks it until the client supplies a sample, and a placeholder
        // here would be a bank format invented by omission.
        SalarySheetReport::class,
        PayrollVarianceReport::class,
        EarningsDeductionsBreakdownReport::class,
        LoanBalanceReport::class,
        CostAllocationReport::class,
        UnpaidSalaryReport::class,
        PayrollLineDetailReport::class,
        // ⚠ A worksheet, not a settlement. It computes no final figure — see
        // Correction C10b and the class docblock. The settlement engine itself
        // is a separate slice and is blocked on the leave-encashment rate.
        SettlementWorksheetReport::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ReportRegistry::class, fn ($app) => new ReportRegistry(
            array_map(fn (string $report) => $app->make($report), self::REPORTS),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunLeaveYearEnd::class]);
        }
    }
}
