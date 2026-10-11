<?php

namespace App\Providers;

use App\Support\Attention\NeedsAttention;
use App\Support\Attention\Sources;
use App\Support\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardEvents;
use App\Support\Dashboards\WidgetRegistry;
use App\Support\Dashboards\Widgets;
use Illuminate\Support\ServiceProvider;

/**
 * The role dashboards' widget registry. A widget is a class plus a line here,
 * the same promise the HR dashboard's WIDGETS list makes.
 */
class DashboardServiceProvider extends ServiceProvider
{
    /** In page order within each dashboard. */
    public const WIDGETS = [
        // What is waiting on somebody, first (plan H.1, row 1).
        Widgets\NeedsAttentionWidget::class,
        // Money
        Widgets\CashPositionWidget::class,
        Widgets\MoneyInTransitWidget::class,
        Widgets\GrossProfitWidget::class,
        Widgets\BankUnmatchedWidget::class,
        Widgets\PeriodLockWidget::class,
        Widgets\IncomeTrendWidget::class,
        Widgets\ReceiptsPaymentsWidget::class,
        Widgets\ReceivablesAgeingWidget::class,
        Widgets\PayablesWidget::class,
        Widgets\MoneyAccountsWidget::class,
        Widgets\FinanceApprovalsWidget::class,
        Widgets\ClaimsToPayWidget::class,
        // Sales
        Widgets\SalesTargetWidget::class,
        Widgets\MyOrdersWidget::class,
        Widgets\OrdersNeedingActionWidget::class,
        Widgets\OrdersByStateWidget::class,
        Widgets\OrdersToDispatchWidget::class,
        Widgets\TopProductsWidget::class,
        // Couriers
        Widgets\CourierParcelsByStatusWidget::class,
        Widgets\CourierParcelsAttentionWidget::class,
        Widgets\CourierCodChainWidget::class,
        Widgets\CourierBalancesWidget::class,
        Widgets\CourierStatementsPendingWidget::class,
        Widgets\CourierHealthWidget::class,
        // People and work
        Widgets\AttendanceTodayWidget::class,
        Widgets\TeamAttendanceWidget::class,
        Widgets\LeavePendingWidget::class,
        Widgets\ApprovalsWaitingWidget::class,
        Widgets\FollowUpsDueWidget::class,
        // Stock
        Widgets\StockAlertsWidget::class,
        Widgets\StockLookupWidget::class,
        Widgets\BusinessHealthWidget::class,
        // Me
        Widgets\MyAttendanceWidget::class,
        Widgets\MyTasksWidget::class,
        Widgets\MyPayslipWidget::class,
        Widgets\MyLeaveWidget::class,
        Widgets\MyClaimsWidget::class,
    ];

    /**
     * Needs attention's feeds (plan C.4). A source is a class plus a line here;
     * each answers its own permission and may fail alone.
     */
    public const ATTENTION_SOURCES = [
        Sources\BookingUnknownSource::class,
        Sources\CourierConnectionSource::class,
        Sources\CourierSettlementOverdueSource::class,
        Sources\CourierStatementProblemsSource::class,
        Sources\DoorDecisionsSource::class,
        Sources\StuckParcelsSource::class,
        Sources\LossClaimsSource::class,
        Sources\FeeVarianceSource::class,
        Sources\BankUnmatchedSource::class,
        Sources\ReceivablesOverdueSource::class,
        Sources\PayablesDueSource::class,
        Sources\ApprovalsWaitingSource::class,
        Sources\StockSource::class,
        Sources\FailedJobsSource::class,
    ];

    public function register(): void
    {
        $this->app->singleton(NeedsAttention::class, fn ($app) => new NeedsAttention(
            array_map(fn (string $class) => $app->make($class), self::ATTENTION_SOURCES),
        ));

        $this->app->singleton(WidgetRegistry::class, fn ($app) => new WidgetRegistry(
            array_map(fn (string $class) => $app->make($class), self::WIDGETS),
        ));

        $this->app->singleton(DashboardAccess::class);
    }

    public function boot(): void
    {
        // Domain model events -> live dashboard broadcasts (DashboardEvents).
        DashboardEvents::register();
    }
}
