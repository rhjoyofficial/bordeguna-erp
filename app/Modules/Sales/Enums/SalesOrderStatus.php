<?php

namespace App\Modules\Sales\Enums;

/**
 * One more case than PurchaseOrderStatus, deliberately: a customer who takes
 * 25 of 40 cartons and stops is ROUTINE in distribution (Closed, the
 * short-close), while a half-received PO that dies is an anomaly handled by
 * its outstanding-stock guard. See SALES-ORDER-ARCHITECTURE.md §8.
 *
 * ⚠ Open / PartiallyDelivered / Delivered are recomputed from the lines inside
 * each delivery transaction. Closed and Cancelled are reached ONLY by explicit
 * action, never by recompute, and Delivered is terminal.
 */
enum SalesOrderStatus: string
{
    case Open = 'open';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
