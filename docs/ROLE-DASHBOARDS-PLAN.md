# Role dashboards: plan (slice "dash", 11 Oct 2026)

Source: courier-hub-2026-10 rulings ("role-based dashboards" + "Owner override"), plan section C.

## Access
- Five dashboards, ranked: owner > finance > manager > sales > employee (`DashboardKey`).
- `dashboard_role_assignments` (business_id, role_id unique, dashboard): which dashboard a role gets.
  Defaults by role name: Owner->owner, Finance->finance, Manager->manager, Staff->sales,
  Self-service->employee. A role with no row falls back to its permissions
  (organization.manage->owner, accounting.view->finance, attendance.manage / stock.approve /
  po.approve->manager, sales.sell / orders.record->sales, else employee).
- Every member may open the employee dashboard (strictly self-scoped).
- `dashboard.view_all` (Owner role, every business; migration + seeder) opens every dashboard;
  a view reached only through it is written to the activity log.
- `/dashboard` renders the highest authorised dashboard; `/dashboard/{key}` the others; the
  switcher lists only what the user may open. The old dashboard lives on at `/dashboard/classic`
  (Owner/Finance/Manager switcher tab), nothing removed.
- Login lands on `/dashboard` unless a role has an explicit landing page (C-LAND kept).
- Settings > Dashboards (dashboard.view_all) edits the mapping.

## Widget registry (`App\Support\Dashboards`)
- `Widget` contract: key, title, category, dashboards, authorize (permission + scope
  business / team / self), data provider (typed payload: stat, series, breakdown, list,
  signals), default visibility, refresh seconds, cache seconds, date basis (null = not
  range-aware), drill-down URL, empty text, notification rules.
- Cache key = widget + business + user scope (user id for team/self) + range.
- A failing provider returns an error state, never zero.
- `dashboard_layouts` (business, user, dashboard, order, hidden): hiding never changes access;
  hidden and unauthorised widgets are never computed nor sent.
- HRM `DashboardRegistry` untouched.

## Date range
Presets today, yesterday, last 7 days, this month, last month, this quarter, this year, custom
(max 366 days, validated); Asia/Dhaka; previous period of equal length; in the URL query.

## Realtime and sound
- `GET /dashboard/widgets/{key}?dashboard=&range=` JSON, polled per widget on its own interval,
  paused when the tab is hidden; "updated N s ago"; stale/error state kept visible.
- The page opts out of the global 30 s Inertia reload (`auto_refresh: false`).
- Web Audio synth, classes success / attention / error; settings in `users.preferences`
  (enable, volume, categories, quiet hours); default ON only for view_all holders; never on
  load or the user's own action; unlocked on first interaction; one sound per 3 s, bursts bundled.
- Widgets declare signals (counts) and rules (increase -> class); the client compares polls.

## Charts and motion
Recharts (area/line trends, bars for comparisons, donut for few categories), units in tooltips,
values also as text. Motion: cards stagger in once, numbers count up on change, list rows
animate in/out, all off under prefers-reduced-motion; no number is delayed.

## Widgets (existing reliable data only; courier widgets later)
Owner: cash & equivalents, with couriers/gateway, receivables + ageing, payables due, income
trend (ledger), gross profit, top products, orders by state, low stock + expiring, approvals,
business health, attendance today.
Finance: money per account, AR/AP ageing, receipts vs payments, unmatched bank lines, period
lock, finance approvals, expense claims to pay.
Manager: team attendance, leave pending, orders awaiting dispatch, low stock, approvals,
follow-ups due.
Sales: my orders today, orders needing action, sales vs target, follow-ups, stock lookup.
Employee: my attendance today + period, leave balance + requests, latest payslip, my claims,
my to-dos/approvals. (No announcements table exists: skipped.)

## Tests
Resolution per business, switcher cannot bypass, view_all logged, endpoint refuses unknown /
unauthorised keys and other employees' data, cache isolation, Dhaka range boundaries, layout
save, sound defaults, each role renders.

## Change of plan (Joy, 11 Oct 2026, mid-slice)
WebSockets now (Laravel Reverb), polling only as the automatic fallback while the socket is down.
Private channels `business.{id}` (members) and `user.{id}` (self); events carry type, ids, widget
keys and a sound class, never figures. Deploy steps: docs/REVERB-DEPLOY.md.
