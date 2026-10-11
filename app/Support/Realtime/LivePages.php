<?php

namespace App\Support\Realtime;

use Illuminate\Http\Request;

/**
 * Which record kinds each page shows, so the browser knows which
 * `record.changed` events should refresh it (realtime everywhere, 11 Oct 2026).
 *
 * Shared as the Inertia prop `live`, derived from the current route name. A
 * controller that knows better returns its own `live` prop, which wins (page
 * props override shared ones).
 *
 * ⚠ A page not listed here gets an empty list and keeps the old 30 s poll, so a
 * screen nobody wired yet never goes stale. A guard test lists the pages that
 * must stay wired.
 *
 * ⚠ Kinds are RealtimeEvents::kinds(); a typo here would silently never
 * refresh, so the guard test checks every kind exists.
 */
final class LivePages
{
    private const SALES_ORDER = ['sales_order', 'approval_request', 'delivery', 'sales_invoice'];

    /** @var array<string, list<string>> route name => kinds */
    public const ROUTES = [
        // Sales
        'sales.orders.index' => self::SALES_ORDER,
        'sales.orders.show' => [...self::SALES_ORDER, 'consignment', 'customer_receipt', 'stock'],
        'sales.deliveries.index' => ['delivery', 'consignment', 'sales_order', 'sales_invoice'],
        'sales.consignments.index' => ['consignment', 'delivery', 'courier_statement'],
        'sales.invoices.index' => ['sales_invoice', 'customer_receipt', 'consignment'],
        'sales.invoices.show' => ['sales_invoice', 'customer_receipt', 'consignment', 'delivery'],
        'sales.receipts.index' => ['customer_receipt'],
        'sales.courier-statements.index' => ['courier_statement'],
        'sales.courier-statements.show' => ['courier_statement', 'consignment'],
        // Purchasing
        'purchasing.purchase-orders.index' => ['purchase_order', 'approval_request', 'supplier_bill'],
        'purchasing.purchase-orders.show' => ['purchase_order', 'approval_request', 'supplier_bill', 'stock'],
        'purchasing.bills.index' => ['supplier_bill', 'approval_request'],
        'purchasing.bills.show' => ['supplier_bill', 'approval_request'],
        // Stock
        'inventory.stock.index' => ['stock'],
        'inventory.low-stocks.index' => ['stock'],
        // Work and approvals
        'approvals.index' => ['approval_request'],
        'approvals.history' => ['approval_request'],
        'work' => ['approval_request', 'leave_request', 'expense_claim', 'sales_order', 'notification'],
        'notifications.index' => ['notification'],
        // Needs attention: anything whose change can add or clear an item.
        'attention' => [
            'consignment', 'courier_statement', 'approval_request', 'stock', 'delivery',
            'sales_invoice', 'customer_receipt', 'supplier_bill', 'voucher', 'journal',
        ],
        // People
        'hrm.attendance.daily.index' => ['attendance_day'],
        'hrm.leave.index' => ['leave_request', 'approval_request'],
        'hrm.my-leave' => ['leave_request', 'approval_request'],
        'hrm.expense-claims.index' => ['expense_claim', 'approval_request'],
        'hrm.my-expenses' => ['expense_claim', 'approval_request'],
        // Accounting
        'accounting.vouchers.index' => ['voucher', 'approval_request'],
        'accounting.vouchers.show' => ['voucher', 'approval_request', 'journal'],
        'accounting.journals.index' => ['journal'],
        'accounting.journals.show' => ['journal'],
    ];

    /** @return list<string> */
    public static function forRoute(?string $name): array
    {
        return $name === null ? [] : (self::ROUTES[$name] ?? []);
    }

    /** @return list<string> */
    public static function forRequest(Request $request): array
    {
        return self::forRoute($request->route()?->getName());
    }
}
