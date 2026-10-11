<?php

namespace App\Support\Realtime;

use App\Events\Realtime\RecordChanged;
use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Where the key models' own saves become `RecordChanged` broadcasts (realtime
 * everywhere, Joy 11 Oct 2026). Wired once in RealtimeServiceProvider::boot, so
 * no business action has to learn about sockets.
 *
 * ⚠⚠ A BROADCAST MUST NEVER BREAK A WRITE. Building and dispatching are
 * wrapped: a failure is reported and swallowed and the record is saved exactly
 * as before (the DashboardEvents rule).
 */
final class RealtimeEvents
{
    /**
     * kind => model. The browser compares these strings with each page's
     * `live` list (LivePages), so a kind is never renamed casually.
     *
     * @var array<string, class-string<Model>>
     */
    public const MODELS = [
        'sales_order' => SalesOrder::class,
        'delivery' => Delivery::class,
        'consignment' => Consignment::class,
        'sales_invoice' => SalesInvoice::class,
        'customer_receipt' => CustomerReceipt::class,
        'courier_statement' => CourierStatement::class,
        'purchase_order' => PurchaseOrder::class,
        'supplier_bill' => SupplierBill::class,
        'approval_request' => ApprovalRequest::class,
        'attendance_day' => DailyAttendance::class,
        'leave_request' => LeaveRequest::class,
        'expense_claim' => ExpenseClaim::class,
        'voucher' => Voucher::class,
        'journal' => Journal::class,
    ];

    /** Kinds that are not one model row each. */
    public const STOCK = 'stock';

    public const NOTIFICATION = 'notification';

    /** @return list<string> every kind a page may declare */
    public static function kinds(): array
    {
        return [...array_keys(self::MODELS), self::STOCK, self::NOTIFICATION];
    }

    public static function register(): void
    {
        foreach (self::MODELS as $kind => $class) {
            $class::saved(function (Model $model) use ($kind) {
                // A save that changed nothing is not news.
                if (! $model->wasRecentlyCreated && ! $model->wasChanged()) {
                    return;
                }

                self::record($kind, $model, $model->wasRecentlyCreated ? RecordChanged::CREATED : RecordChanged::UPDATED);
            });

            $class::deleted(fn (Model $model) => self::record($kind, $model, RecordChanged::DELETED));
        }

        // Stock is announced per PRODUCT, once per request or job, whatever
        // number of batches the movement touched.
        StockMovement::created(function (StockMovement $movement) {
            if ($movement->business_id === null || $movement->product_id === null) {
                return;
            }

            self::send(self::STOCK.':'.$movement->product_id, fn () => RecordChanged::forBusiness(
                self::STOCK, (int) $movement->product_id, RecordChanged::UPDATED, (int) $movement->business_id, self::actor(),
            ));
        });

        // A notification goes to its one recipient's own channel, never the business.
        DatabaseNotification::created(function (DatabaseNotification $notification) {
            if ($notification->notifiable_type !== (new User)->getMorphClass()) {
                return;
            }

            self::send(self::NOTIFICATION.':'.$notification->id, fn () => RecordChanged::forUser(
                self::NOTIFICATION, (string) $notification->id, RecordChanged::CREATED, (int) $notification->notifiable_id,
                self::currentBusiness(), self::actor(),
            ));
        });

        // A booking that failed or was lost writes no parcel row, but the
        // challan and parcels screens still have something new to show.
        Event::listen(CourierParcelChanged::class, function (CourierParcelChanged $event) {
            self::send('delivery:'.$event->deliveryId, fn () => RecordChanged::forBusiness(
                'delivery', $event->deliveryId, RecordChanged::UPDATED, $event->businessId, self::actor(),
            ));
        });
    }

    private static function record(string $kind, Model $model, string $action): void
    {
        $businessId = $model->getAttribute('business_id');

        if ($businessId === null || $model->getKey() === null) {
            return;
        }

        self::send("{$kind}:{$model->getKey()}", fn () => RecordChanged::forBusiness(
            $kind, (int) $model->getKey(), $action, (int) $businessId, self::actor(),
        ));
    }

    /** @param callable(): RecordChanged $build */
    private static function send(string $key, callable $build): void
    {
        try {
            if (! app(RecordChangeBuffer::class)->first($key)) {
                return;
            }

            event($build());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function actor(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    private static function currentBusiness(): ?int
    {
        try {
            $id = app(BusinessContext::class)->id();

            return $id === null ? null : (int) $id;
        } catch (Throwable) {
            return null;
        }
    }
}
