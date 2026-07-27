<?php

namespace App\Modules\Hrm\Providers;

use App\Modules\Hrm\Console\Commands\RunLeaveYearEnd;
use App\Modules\Hrm\Reporting\Definitions\DailyAttendanceReport;
use App\Modules\Hrm\Reporting\Definitions\SalarySheetReport;
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
        DailyAttendanceReport::class,
        SalarySheetReport::class,
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
