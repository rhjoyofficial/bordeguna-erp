# Slice 0: dashboard and money-figure defects

Branch `fix/slice0-dashboard-bugs` (from master `a85e75ed`). One commit per
defect, each with a Pest feature test that fails before the fix.

| # | Defect | Where | Fix |
|---|--------|-------|-----|
| D1 | A reversed supplier bill still counts as owed / purchased | `Ageing::payables()`, `DashboardController` (total + trend), finance dashboard via Ageing | Leave out bills reversed on or before the as-at date (the reversal journal is dated the day it was reversed, so the control-account tie-out stays exact); dashboard leaves out reversed bills |
| D2 | "Cash" includes courier (1145) and gateway (1146) money | `GroupCockpitController`, `CashFlowForecast::build()`, `SendMorningBriefing` | Cash = lines on heads under the three money groups (`MoneyLedgers::groupIds()`); cockpit shows courier/gateway money as its own figure |
| D3 | `group/cockpit` has no permission gate | route + controller + sidebar | A business's figures only where the user holds `accounting.view` in that business (team id switched per business, restored); none at all = 403; sidebar link follows the same rule |
| D4 | A courier/gateway account's balance is every courier's combined | `MoneyAvailability::balanceMinor()` / `lowestFromMinor()` | Courier and gateway accounts filter by `journal_lines.money_account_id` |
| D7 | Expiry "soon" is a flat 30 days | `ExpiryController` | Each product's own `shelfLifeWarningDays()` window |
| D8 | Owner's Drawings (3200) and Loans from Owners (2220) have no slug | `ChartOfAccountsSeeder` + migration | Slugs `owners_drawings`, `owner_loans`; reversible migration by code |

Finish: full suite `php artisan test --parallel --processes=3`.
