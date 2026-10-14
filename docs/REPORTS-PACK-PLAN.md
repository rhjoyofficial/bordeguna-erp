# Reports pack: sales, margin, delivery economics, cash position, courier ageing

Branch `feature/reports-pack` from master `5b857a07`. Source of the gaps: the
11 Oct audit of accounting, sales recognition and dashboards (no sales by
channel or customer type, no margin, no cash position; "cash" mixing in money
with couriers and gateways; advances in two places).

Every report is a definition in the shared registry (`Hrm\Reporting\ReportRegistry`),
so CSV, XLSX, PDF and print come from the existing writers, and the run is
permission-checked and activity-logged by the one controller.

## What is added to the framework

- **On-screen view.** `GET hrm/reports/{report}/preview` returns the same
  dataset as JSON (first 500 rows, the totals over every row, the footnote).
  The report screen shows it as a table, and as a small Recharts chart when the
  definition declares one through a new optional `ChartsOnScreen` interface.
  No second query: the chart is drawn from the preview rows.
- **Basis footnote.** Each new report states its exact basis in `meta()->note`,
  which the PDF and print view already print under the table and the screen now
  shows. The XLSX carries it in the document description beside the stamp.
- **Select labels.** A `select` parameter is described by its option label in
  the report header ("Brand: LaLaDia"), not its id.
- **Sidebar.** Each report gets a link: the sales ones under Sales for
  `sales.reports.view`, cash position under Financial reports for `accounting.view`.

## The five reports

### 1. Sales by brand, channel and customer type (`sales_by_channel`, sales.reports.view)
Basis: invoiced. One row per brand x channel x customer type.
- Goods revenue ex-VAT = invoice line `line_net` less the VAT inside it (inclusive
  prices), exactly what 4110 Product Sales (under Sales Revenue 4100) is credited.
- Discounts = the invoice discount's share of each goods line (stored
  `discount_share` when VAT spread it, else pro rata on `line_net`, the rule
  ApplyInvoiceTax uses), net of VAT: what 4210 is debited.
- Returns = return lines dated in the period, `credit_gross - tax_inside_gross`,
  less the discount clawed back: what 4200 is debited and 4210 credited.
- Net revenue = goods - discounts - returns, which ties to the period movement
  on 4110 + 4200 + 4210.
- Delivery charges billed (4300) are shown in their own column and are NOT in
  net revenue. VAT, invoice totals, GDNI and advances are never added.
- Brand and channel come from the ORDER, through the challan line. No order
  (counter sale, challan without an order) = channel Counter. No brand = "No brand".
- Filters: invoiced between, brand (incl. No brand), channel.

### 2. Product and brand margin (`sales_margin`, sales.reports.costs)
Basis: invoiced. Group by product or by brand (order brand, as report 1).
Revenue = net revenue as report 1. COGS = `cost_released` less return
`cogs_relief`, the derivation CostOfGoodsSold and the reconciliation use (ties
to 5100 movement). Margin and margin %. Quantity = invoiced less the invoiced
part returned. Flag "Cost known": Yes / Partly / No (goods lines sold at zero
released cost).

### 3. Delivery economics (`delivery_economics`, sales.reports.costs)
Basis: parcels whose challan is dated in the period. Per parcel, or totals by
courier, brand or band.
- Charge billed = invoice lines with `billed_delivery_for_order_id` for the
  parcel's order(s), ex-VAT (4300). An order billed once and sent in two parcels
  counts on its first parcel.
- Courier cost, labelled by source: posted statement line (net fee + COD fee) >
  statement line not yet posted > courier's quote at booking > fee estimate >
  none. Extra charges, compensation and adjustments are not parcel cost here.
- Margin = charge - cost. COD fee share = COD fee / cost.
- Band = the fee estimate's destination band, else "Not estimated".

### 4. Cash position as at a date (`cash_position`, accounting.view)
Basis: posted journal lines dated on or before the day.
- Cash and bank: each cash, bank and MFS ledger head (MoneyLedgers groups),
  labelled with its money account. Total ties to `MoneyLedgers::cashLines`.
- Not cash, owed to us: with each courier (1145 by `money_account_id`), with
  each gateway (1146 by `money_account_id`).
- Not cash, owed by us: payable to each courier (2165 by `money_account_id`),
  customer advances (2150 balance) and receipts not yet applied to an invoice
  (held in 1140; receipts to the day less allocations to invoices to the day).
- Three money columns (cash, owed to us, owed by us) so no total ever adds an
  asset to a liability.

### 5. Courier settlement ageing (`courier_settlement_ageing`, sales.reports.view)
Basis: parcels recorded delivered or part delivered with cash collected, on no
posted statement as at the day (the COD chain widget's "recorded, not settled").
Age = days from the courier's answer (`outcome_on`). Buckets 0-3, 4-7, 8-14,
15+ per courier, with an overdue column over
`finance_policies.courier_settlement_alert_days`. Detail per parcel or summary
per courier. Brand filter.

## Tests
Per report, on a small fixture made through the real actions where journals
matter: sums tie to journal lines / documents; brand and business isolation;
permission (registry hides the report, the run 404s); no double counting
(invoice total vs revenue, VAT and delivery out of revenue, GDNI excluded,
advances not revenue, courier money not cash). Then EveryModuleIsReachableTest,
NoEmDashTest, BanglaDictionaryTest, tsc, prettier on changed files.

## Cost reports are not for Staff
The seeded Staff role holds `sales.reports.view`. Margin and delivery economics
show cost (COGS, courier charges), so they need `sales.reports.costs`, granted
to Owner, Manager and Finance by the seeder and, for roles that already exist,
by migration `2026_10_11_800001`. The registry hides them, and the screen,
preview and every export 404 without it; the sidebar links follow
`canViewCostReports`.
