<?php

namespace App\Events\Dashboards;

/**
 * The event names that travel over the dashboard channels, and that widgets
 * list in `refreshOn()`. Strings, not classes, because the browser compares
 * them and the generic DashboardSignal carries any of the "signal" family.
 */
final class DashboardEventType
{
    public const ORDER_CREATED = 'sales.order.created';

    public const ORDER_APPROVED = 'sales.order.approved';

    public const PAYMENT_RECEIVED = 'sales.payment.received';

    public const APPROVAL_REQUESTED = 'approval.requested';

    public const APPROVAL_DECIDED = 'approval.decided';

    public const STOCK_LOW = 'stock.low';

    public const CLAIM_SUBMITTED = 'expense_claim.submitted';

    public const LEAVE_DECIDED = 'leave.decided';

    public const PAYSLIP_READY = 'payslip.ready';

    // The generic family DashboardSignal is for (courier code fires these later).
    public const PARCEL_STATUS = 'courier.parcel_status_changed';

    public const BOOKING_FAILED = 'courier.booking_failed';

    public const SYNC_ERROR = 'integration.sync_error';

    public const STATEMENT_IMPORTED = 'courier.statement_imported';

    /** The sound class each event plays unless it says otherwise. */
    public const SOUNDS = [
        self::ORDER_CREATED => 'success',
        self::ORDER_APPROVED => 'success',
        self::PAYMENT_RECEIVED => 'success',
        self::APPROVAL_REQUESTED => 'attention',
        self::APPROVAL_DECIDED => 'success',
        self::STOCK_LOW => 'attention',
        self::CLAIM_SUBMITTED => 'attention',
        self::LEAVE_DECIDED => 'success',
        self::PAYSLIP_READY => 'success',
        self::PARCEL_STATUS => 'success',
        self::BOOKING_FAILED => 'error',
        self::SYNC_ERROR => 'error',
        self::STATEMENT_IMPORTED => 'success',
    ];

    public static function soundFor(string $type): string
    {
        return self::SOUNDS[$type] ?? 'attention';
    }
}
