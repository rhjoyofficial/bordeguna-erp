# Needs attention, failed jobs and Bangla for the courier and dashboard screens

Branch `feature/needs-attention-bangla`, from master `7cbb7cf3`. Plan section C.4 (Needs attention), H (UI states), ChatGPT dashboard rulings 2, 7, 9 and 10.

## 1. Needs attention centre

One ranked list of things a person should act on, at `/attention` (sidebar, Overview, with a count badge) and as a widget on the Owner, Finance and Manager dashboards.

Every item carries: `severity` (critical, high, normal), `area` (couriers, money, stock, work, system), `owner` (the role or person who should act), `since` (so the age is computed, never stored), a one-line `reason`, and an `action` link to the screen where the fix lives.

### Sources (each behind its own permission, each failure-isolated)

| Source | Permission | Severity |
|---|---|---|
| Delivered parcels unsettled beyond `finance_policies.courier_settlement_alert_days` | `sales.reports.view` or `accounting.view` | high, critical past twice the days |
| Draft courier statements with unmatched rows, differences or row errors (CourierStatementReview, the statement screen's own check) | `sales.reports.view` | high |
| Door differences waiting for a decision (pending CourierDoorDiscountRequest) | `sales.parcels.see` | high |
| Parcels stuck more than 3 days in one courier status, and courier problem statuses | `sales.parcels.see` | normal, high for lost or damaged |
| Booking answers lost (booking intent unknown or stale) | `sales.parcels.see` | critical |
| Courier API failing, or no webhook for 24 h on an active account with open parcels | `sales.parcels.see` | critical / high |
| Stale data: no courier sync for 2 h while parcels are open | `sales.parcels.see` | high |
| Open loss claims | `sales.reports.view` | normal |
| Fee estimates more than 25% off the courier's charge (last 30 days, CourierFeeVariance) | `couriers.ratecards.propose` | normal |
| Unmatched bank lines | `accounting.view` | normal, high past 7 days |
| Approvals waiting on me (ApprovalInbox) | personal | high |
| Low stock, out of stock, expiring, expired | `inventory.reports.view` | normal / high / critical |
| Failed queue jobs | `system.jobs.manage` (new, Owner) | critical |
| Receivables overdue more than 30 days (Ageing::receivables) | `accounting.view` | high |
| Payables due in the next 7 days (Ageing::payables) | `accounting.view` | normal, high when already overdue |

A source that throws is reported and dropped, and the page says which area could not be read (never a silent zero).

Ranking: severity first, then age (oldest first), then area.

Filters: severity, area, brand (only when `sales_orders.brand_id` exists; guarded with `Schema::hasColumn`), "assigned to me" (items whose owner is me or one of my roles).

Realtime: the page declares its `live` kinds (consignment, courier_statement, approval_request, stock, sales_invoice, supplier_bill, customer_receipt) and also re-reads on any `dashboard.event`. The sidebar badge is a shared prop cached 60 s per business and user.

## 2. Failed jobs screen

`/system/failed-jobs`, permission `system.jobs.manage`, granted to Owner by migration and by FoundationPermissionsSeeder. Lists `failed_jobs`: when, queue, job class (from the payload's `displayName`), the first lines of the exception with anything that looks like a secret replaced by `[hidden]`. Retry one (`queue:retry {uuid}`), forget one (`queue:forget {uuid}`), retry all.

⚠ `failed_jobs` has no business column: the screen is system-wide. Only the Owner role gets the permission by default.

## 3. Bangla

New screens get `useTranslation`: Settings > Couriers, Locations, Rate cards, Courier fees, Parcels (courier status names), Courier payouts v2, booking panel, outcome form and door resolutions, loss claim, the role dashboards (toolbar, presets, switcher, sound settings, widget card states) and Needs attention.

The dictionary grows by area in `resources/js/lib/i18n/*.ts`, spread into the one typed dictionary in `i18n.ts`, so a key is still a compile-time check. BanglaDictionaryTest reads every dictionary file. Digits, dates and money never translate. Server-sent widget titles stay English on the server and are translated in the browser by widget key.

## 4. Tests

Attention sources authorised per permission, ranking and age, failure isolation, the widget, the badge; failed jobs gated, retry and forget, secrets stripped; translations present for the new screens.
