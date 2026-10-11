# QA walk of the new screens (11 Oct 2026)

Branch `fix/new-screens-qa` from master `7cbb7cf3`.

## How it was walked

- Scratch SQLite database (`database/qa.sqlite`, gitignored, deleted afterwards): `migrate`,
  `db:seed`, then a throwaway script (not committed) added to LaLaDia (business 1): two brands, four
  products (three weighed, one not), CarryBee + Pathao courier money accounts, a bank account,
  location maps (Dhanmondi in use, Mirpur waiting for review), CarryBee + Pathao courier accounts
  with FAKE credentials, a third account never tested, an order with an unbooked challan, an order
  to Mirpur with an unweighed product, four parcels typed by hand, a Manager's door discount above
  the cap, a clean delivered parcel and the sanitised CarryBee statement from `tests/fixtures`.
- ⚠ No courier was called. Each account's base URL was forced to `http://127.0.0.1:9` after saving.
  The ERP's own guard then refused to sign in ("not an official Pathao address"), so even the
  booking attempt stopped before the network.
- `npm run build`, `php artisan serve` on 127.0.0.1, sessions signed server-side (no password
  typed), headless Chrome through Playwright. Users: Owner, Staff, Manager, Finance, Sales Officer.
- Every page was checked by script for console errors, page errors, HTTP 4xx/5xx, horizontal page
  scroll at 390 px, four-decimal numbers, em dashes and NaN/undefined. Screenshots were looked at
  by eye (1440 and 390, light; key screens dark). No screenshots are committed.

## Screens

| Screen | Checked | Result |
|---|---|---|
| Settings > Couriers | 3 accounts (active, active, untested), Activate disabled until tested, Test, Deactivate, business link (store, money account, default), webhook URL + Rotate, auto-post off, known couriers; 1440/390; Staff, Manager get 403 | OK |
| Couriers > Locations | Pathao/CarryBee tabs, counters, Sync/Match/Approve all high, review tabs, search, Approve/Change row, bands editor (districts, thanas, catch-all, new band); 1440/390 | OK; at 390 the review table scrolls inside its box (Approve/Change reached by scrolling the table) |
| Couriers > Rate cards | Both couriers, in-force card, lines, notes, Export CSV (200 text/csv), new draft, propose, retire | OK |
| Sales > Courier fees | Filters, cards in force, summaries, empty band/week charts, parcel table; 1440/390 | Fixed: courier codes shown ("carrybee") |
| Order page booking panel | Estimate (CarryBee, then Pathao on change: "Medium", return-fee scenario), booking form, failed booking message, manual mode, timeline/settlement panel, outcome form (delivered / partly / returned / paid return, door resolutions), Report lost or damaged form; 1440/390, dark | Fixed: three defects (below) |
| Sales > Parcels | Courier balances, search, status filter, table; 1440/390 | OK |
| Sales > Courier payouts | Upload form, list, statement screen (equation, blockers, tabs All/Matched/Differences/Not matched, Ours/Not ours, post form disabled while blocked), errors CSV and workbook downloads (200); 1440/390 | Fixed: "-0.00" in the equation, empty Money card |
| Inventory > Product weights | Count of unweighed, template download (200), upload preview with a change, a blank, an unknown SKU and a bad value (every row listed, Apply blocked) | OK |
| Order form weight | Parcel weight on the order, weight field on the booking form with the suggestion | OK |
| Dashboards | /dashboard per role (Owner, Finance, Manager, Sales and counter, My dashboard), switcher only shows allowed ones (others 403), range presets update the URL, Customise panel, Sound popover, charts, empty states, widget refresh; no console error with Reverb unconfigured ("Refreshing on a timer") | Fixed: one widget cut |
| Settings > Dashboards | Role table, automatic choice | Fixed: picker too narrow |
| Header search (Ctrl+K) | "courier" gives a Menus group of 5 (payouts, fees, settings, locations, rate cards) | OK |
| Approvals inbox | Door discount card (courier, customer, owed, collected, discount, reason), Approve as Owner, the order then shows Delivered with the discount and invoice; 390 no overflow | OK |

## Defects found and fixed

1. **Order page sent the person to a form that was hidden.** A parcel whose door discount waits for
   the Owner showed "Next: Record the courier's answer" with a button, but the challan hides the
   answer form until the Owner decides. Now the step is "Decide the door discount", linking to
   Approvals only for someone who may decide it. Test added in
   `CourierSettlementHardeningTest` ("tells the order page a parcel waits for the Owner...").
2. **"Invoice this challan" on a parcel's challan.** A parcel is billed by the courier's answer and
   the invoice screen already leaves booked parcels out, so the link led nowhere useful. Hidden when
   the challan has a consignment.
3. **Courier fees report showed courier codes** ("carrybee / Dhaka", "Cards in force: pathao ...").
   Now names.
4. **Payout equation showed "-0.00"** (and could show "+-" for a negative term). Signs are applied
   to the value now.
5. **Draft payout's Money card was empty** when a deposit is expected. It now says nothing is in the
   books yet and to record the payout when it arrives.
6. **Estimate money with one decimal** ("Tk 117.7", "COD fee 27.7"). Always two decimals now.
7. **Courier connections widget read "CarryBee CarryBee LaLaDia"**. Now "CarryBee: label", as on
   Settings > Couriers.
8. **Courier statements to post widget** sat in one column at 1440, cutting its title and the
   Parcels column. Now two columns.
9. **Settings > Dashboards picker** cut "Automatic (from its permissions: Sales and c...". Wider.
10. **Courier's answer form labels** were not tied to their fields (What the courier reported, On,
    Collected). Now `htmlFor`/`id`.

Tests run: `tests/Feature/Sales/Couriers`, `tests/Feature/Dashboards`, `SalesNextStepsTest`,
`CourierConsignmentTest`: 170 passed. `tsc --noEmit` clean, Pint and ESLint clean on changed files.
Full suite not run (memory limit on this machine).

## Left for a person

- **Sound**: the popover and settings render; whether a sound is audible, its volume and quiet
  hours need ears in a real browser.
- **Real courier calls**: Test connection, Sync locations, booking, status refresh, cancel and the
  Pathao live quote were not exercised against CarryBee or Pathao (fake credentials only). The
  failure path (booking refused, shown on the challan, form kept) was seen.
- **WebSockets live**: only the fallback (Reverb not configured) was seen; a pushed update needs a
  running Reverb.
- **Not fixed, judgement calls**:
  - At 390 px the locations review table and the payout lines table scroll sideways inside their
    box; the action buttons are reached by scrolling the table.
  - Parcels list shows a parcel waiting on a door discount as plain "With the courier".
  - Settings > Couriers shows the last test time raw ("2026-10-11 15:31:58").
  - Sales Officer gets 403 on sales orders and parcels (role permissions, not a screen defect).
  - "Approve all high (1)" counts an in-use manual map nobody reviewed; by design (it records
    agreement), but the count can read oddly.
