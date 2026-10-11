<?php

namespace App\Support\Dashboards;

use App\Events\Dashboards\ApprovalDecided;
use App\Events\Dashboards\ApprovalRequested;
use App\Events\Dashboards\CustomerPaymentReceived;
use App\Events\Dashboards\DashboardBroadcast;
use App\Events\Dashboards\DashboardEventType;
use App\Events\Dashboards\DashboardSignal;
use App\Events\Dashboards\ExpenseClaimSubmitted;
use App\Events\Dashboards\SalesOrderApproved;
use App\Events\Dashboards\SalesOrderCreated;
use App\Events\Dashboards\StockLow;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Where the domain's own model events become dashboard broadcasts. Wired once
 * in DashboardServiceProvider::boot, so no business action had to learn about
 * dashboards.
 *
 * ⚠⚠ A BROADCAST MUST NEVER BREAK A WRITE. Everything here is wrapped: a
 * failure to build or queue an event is reported and swallowed, and the order,
 * receipt or approval it describes is saved exactly as before.
 */
final class DashboardEvents
{
    public static function register(): void
    {
        SalesOrder::created(fn (SalesOrder $order) => self::send(fn () => SalesOrderCreated::for((int) $order->business_id, $order->id, self::actor())));

        CustomerReceipt::created(fn (CustomerReceipt $receipt) => self::send(fn () => CustomerPaymentReceived::for((int) $receipt->business_id, $receipt->id, self::actor())));

        ApprovalRequest::created(function (ApprovalRequest $request) {
            self::send(fn () => ApprovalRequested::for((int) $request->business_id, $request->id, self::actor()));

            if (self::approvableIs($request, ExpenseClaim::class)) {
                self::send(fn () => ExpenseClaimSubmitted::for((int) $request->business_id, (int) $request->approvable_id, self::actor()));
            }
        });

        ApprovalRequest::updated(function (ApprovalRequest $request) {
            if (! $request->wasChanged('status')) {
                return;
            }

            $status = $request->status;

            if ($status === ApprovalStatus::Pending) {
                // Resubmitted after being sent back: it is waiting again.
                self::send(fn () => ApprovalRequested::for((int) $request->business_id, $request->id, self::actor()));

                return;
            }

            $requester = $request->requested_by !== null ? [(int) $request->requested_by] : [];
            self::send(fn () => ApprovalDecided::for((int) $request->business_id, $request->id, self::actor(), $requester));

            if ($status === ApprovalStatus::Approved && self::approvableIs($request, SalesOrder::class)) {
                self::send(fn () => SalesOrderApproved::for((int) $request->business_id, (int) $request->approvable_id, self::actor()));
            }

            if (self::approvableIs($request, LeaveRequest::class)) {
                $userId = LeaveRequest::withoutGlobalScopes()->find($request->approvable_id)?->employee()->withoutGlobalScopes()->value('user_id');

                if ($userId !== null) {
                    self::send(fn () => DashboardSignal::personal(DashboardEventType::LEAVE_DECIDED, (int) $request->business_id, (int) $userId, ['leave' => (int) $request->approvable_id], self::actor()));
                }
            }
        });

        PayrollRun::updated(function (PayrollRun $run) {
            if (! $run->wasChanged('status') || $run->status !== PayrollStatus::Approved) {
                return;
            }

            $userIds = Employee::withoutGlobalScopes()
                ->whereIn('id', Payslip::withoutGlobalScopes()->where('payroll_run_id', $run->id)->pluck('employee_id'))
                ->whereNotNull('user_id')
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                self::send(fn () => DashboardSignal::personal(DashboardEventType::PAYSLIP_READY, (int) $run->business_id, (int) $userId, ['run' => $run->id], self::actor()));
            }
        });

        StockBatch::updated(function (StockBatch $batch) {
            if (! $batch->wasChanged('current_quantity')) {
                return;
            }

            $drop = (float) $batch->getOriginal('current_quantity') - (float) $batch->current_quantity;

            if ($drop <= 0) {
                return;
            }

            self::send(function () use ($batch, $drop) {
                $product = Product::withoutGlobalScopes()->find($batch->product_id);

                if ($product === null || $product->low_stock_threshold === null) {
                    return null;
                }

                $total = (float) StockBatch::withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('status', StockBatchStatus::Active)
                    ->sum('current_quantity');

                // Only the moment it CROSSES the line, not every sale below it.
                $crossed = Product::stockLevelIsLow($product->low_stock_threshold, $total)
                    && ! Product::stockLevelIsLow($product->low_stock_threshold, $total + $drop);

                return $crossed ? StockLow::for((int) $batch->business_id, $product->id, self::actor()) : null;
            });
        });
    }

    /** @param callable(): ?DashboardBroadcast $build */
    private static function send(callable $build): void
    {
        try {
            $event = $build();

            if ($event !== null) {
                event($event);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function actor(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    private static function approvableIs(ApprovalRequest $request, string $class): bool
    {
        return $request->approvable_type === $class
            || $request->approvable_type === (new $class)->getMorphClass();
    }
}
