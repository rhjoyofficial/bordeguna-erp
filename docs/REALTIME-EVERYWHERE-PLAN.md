# Realtime everywhere (Joy, 11 Oct 2026)

> "Everything shows real time, no reload, good decision for everywhere."

Builds on the Reverb base the role dashboards brought (`business.{id}` and
`user.{id}` private channels, `DashboardBroadcast`, `resources/js/lib/echo.ts`).
ChatGPT rulings: `courier-hub-2026-10/CHATGPT-RULINGS-2026-10-11.md`
("WebSockets NOW", "realtime everywhere").

## 1. One generic event: `RecordChanged`

`App\Events\Realtime\RecordChanged`, broadcast as `record.changed`, queued and
released only after the transaction commits (ShouldDispatchAfterCommit), same
as the dashboard events.

Payload, and nothing else (a test asserts the keys):

| key | meaning |
|---|---|
| `kind` | model kind, e.g. `sales_order` |
| `id` | record id (product id for `stock`) |
| `action` | `created`, `updated` or `deleted` |
| `business_id` | the business it belongs to |
| `actor_id` | who did it, null for the scheduler, a webhook or a queue job |
| `at` | ISO time |

NO field values, amounts, names or statuses: a page re-reads itself through its
own authorised controller, so a subscriber never learns more than their
permissions already show.

Channels: `business.{id}` for business records; `user.{id}` only for a
notification addressed to that one person (never the business channel).

Fired from model hooks registered once (`App\Support\Realtime\RealtimeEvents`),
so no business action has to know about it. Kinds and models:

| kind | model |
|---|---|
| sales_order | Sales\SalesOrder |
| delivery | Sales\Delivery |
| consignment | Sales\Consignment (includes courier status changes, which update the row) |
| sales_invoice | Sales\SalesInvoice |
| customer_receipt | Sales\CustomerReceipt |
| courier_statement | Sales\CourierStatement |
| purchase_order | Purchasing\PurchaseOrder |
| supplier_bill | Purchasing\SupplierBill |
| stock | Inventory\StockMovement (id = product id, one per product per request/job) |
| approval_request | Foundation\ApprovalRequest |
| attendance_day | Hrm\DailyAttendance |
| leave_request | Hrm\LeaveRequest |
| expense_claim | Hrm\ExpenseClaim |
| voucher | Accounting\Voucher |
| journal | Accounting\Journal |
| notification | Laravel DatabaseNotification, to the user channel only |

`CourierParcelChanged` (booking failed / unknown, no consignment row written)
also sends `delivery` so the challan and parcels screens refresh.

Burst control on the server: a request-scoped buffer sends each (kind, id) once
per request or queue job (a 30-line sale moves stock on 30 batches of 4
products: 4 `stock` events, not 30). The browser debounces further.

⚠ A broadcast must never break a write: building and dispatching are wrapped
and reported, exactly like DashboardEvents.

## 2. Which page listens to what

`App\Support\Realtime\LivePages` maps route names to the kinds the page shows
and is shared as the Inertia prop `live` (derived from the current route). A
controller may override it by returning its own `live` prop. Shared
`realtime` prop: `{enabled, business_channel, user_channel}`.

Pages wired (a guard test fails if one loses its declaration or its route):
sales orders index/show, deliveries, consignments (parcels), invoices
index/show, receipts, courier statements index/show, purchase orders
index/show, supplier bills index/show, stock + low stock, approvals inbox +
history, my work, notifications, attendance daily, leave (+ my leave), expense
claims (+ my expenses), vouchers index/show, journals index/show.

The header bell is global: a `notification` on my channel reloads only
`unreadNotificationsCount` + `recentNotifications`; an `approval_request`
reloads only `pendingApprovalsCount` for approvers. On every page.

## 3. Frontend: `useLiveReload` in the app shell

`resources/js/lib/live-reload.ts` (module state, survives the layout remount
on navigation) + `resources/js/hooks/use-live-reload.ts` (mounted in
AppSidebarLayout).

- Subscribes to `business.{id}` and `user.{id}`, listens for `.record.changed`.
  Never `leave()`s a channel (the dashboard hook shares it); removes only its
  own listener. The dashboard hook is changed the same way.
- An event whose `kind` is in the page's `live` list queues a reload. Bursts are
  debounced (500 ms) into ONE `router.reload()` (Inertia keeps scroll and
  component state, so form values, open dialogs and tabs survive).
- Deferred, not dropped, while: a write is in flight, the tab is hidden, focus
  is in an editable field, a dialog/sheet is open, a field inside a `<form>`
  was typed in since the last visit, or the page is a POST response (import
  preview). It retries every second and on tab focus; one reload, not many.
- Own writes: an event with `actor_id` = me within 4 s of this tab finishing a
  write is skipped (the redirect already refreshed the page). From another tab
  it still reloads.
- Pages that send `auto_refresh: false` (the role dashboards) are not reloaded;
  the bell partials still apply.
- The 30 s timer in app.tsx stays ONLY as a fallback: it stands down for a page
  with a `live` list while the socket is connected. Pages without a `live` list
  keep the 30 s poll (no regression for screens not wired yet).
- "Updated just now" pulse (Motion, honours reduced motion) after a live reload.
  No sound outside the dashboards.

## 4. Courier widgets (registry)

| key | dashboards | permission | what |
|---|---|---|---|
| courier_parcels_by_status | Owner, Manager, Sales | sales.parcels.see | consignments.courier_status: open statuses as at now, final ones by status date in range |
| courier_parcels_attention | Owner, Manager, Sales | sales.parcels.see | booking unknown, delivery failed, on hold, stuck > 3 days in one open status |
| courier_cod_chain | Owner, Manager, Sales, Finance | sales.reports.view or accounting.view | expected COD booked -> courier says collected -> recorded in ERP (1145) unsettled -> settled in range (posted statement) -> overdue (finance_policies.courier_settlement_alert_days) |
| courier_balances | Owner, Manager, Sales, Finance | accounting.view | per courier money account: 1145 receivable, 2165 payable (posted journal lines by money_account_id) |
| courier_integration_health | Owner, Manager, Sales | sales.parcels.see | per linked courier account: active, last good API call, last error, last webhook |
| courier_statements_pending | Owner, Finance | sales.reports.view | draft statements awaiting posting |

Each courier money account (and each courier account) stays its own row; two
couriers are never summed together (Joy, 11 Oct: every courier's balance
separate).

Signals: `CourierParcelChanged` -> `DashboardSignal` (`courier.parcel_status_changed`):
delivered = success, delivery failed / on hold / lost / damaged = attention,
booking failed = `courier.booking_failed` (error), booking unknown = attention.
Statement imported / posted -> `courier.statement_imported` (+ a
`courier.statement_posted` type).

## 5. Tests

`tests/Feature/Realtime/RecordChangedTest.php`, `LivePagesTest.php`,
`tests/Feature/Dashboards/CourierWidgetsTest.php`: payload keys and no values,
right channel, user channel only for that user, once per request, page
declarations guard, widgets authorised, per-courier sums kept apart, overdue
uses the policy days, signals on parcel change.

## Judgement calls

- Stuck = 3 days in one open courier status (constant, named in the widget
  description); can become a policy field later.
- COD chain is operational (from parcels); the ledger view is
  `courier_balances`. Both labelled with their basis.
- No field values ever, not even the status word: the page re-reads.
