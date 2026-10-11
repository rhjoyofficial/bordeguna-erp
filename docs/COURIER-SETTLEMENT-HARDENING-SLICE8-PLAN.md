# Courier hub slice 8: settlement hardening (the outcomes the ERP refuses today)

Branch `feature/settlement-hardening` from master `b04dd99b`. Source: courier hub plan section D rows 6-12,
ChatGPT rulings 2, 3, 5, 6 and risks 1, 2, 3, 4, 9, 11, Joy's approved accounts 4960 Courier Compensation
Income and 2165 Payable to Couriers (every line on 1145 / 2165 / 4960 names the courier's money account).

Recognition policy unchanged: revenue at invoice, a courier parcel is invoiced when its outcome is recorded.
Every journal goes through `PostJournal`; posted journals are never edited.

## 1. Collected differs from what is owed (ruling 3)

The outcome form asks for a **resolution** whenever the collected amount differs from what the invoice still
owes after any advance. A reason is mandatory for every resolution. One outcome per parcel, as today.

| Resolution | Allowed when | Documents and journals |
|---|---|---|
| Discount at the door | collected < due | Invoice at the agreed price; receipt into the courier for what was collected (Dr 1145 courier / Cr 1140); a `customer_discount` party adjustment for the difference against that invoice (Dr 4210 / Cr 1140). Within `DiscountCap` for the confirming user; above it, or without `sales.price.override`, nothing is posted and an Owner approval request (workflow `courier_door_discount`) holds the whole outcome until the Owner approves it. |
| Price correction | any difference | Needs `sales.price.override`. Invoice lines at the corrected unit prices, each keeping the list price, the reason and who changed it (the counter's price trail). Must then make the amount due equal to what was collected, or it is refused naming both. |
| Short collection | collected < due | Invoice at full price, receipt for what was collected, the remainder stays on the customer's account (AR). |
| Rounding | difference below Tk 1 | Collected less: receipt for what was collected, the paisa written off through a new party adjustment kind `rounding` (Dr `accounting.rounding_difference`, else 4210 / Cr 1140). Collected more (COD rounded up to whole taka): receipt for what was due, the paisa Dr 1145 courier / Cr rounding (else 4210). |

Technical vs accounting authority (risk 11): `couriers.parcels.book` gives none of this. The outcome route keeps
`sales.sell` + `sales.receipt.record` / `sales.returns.record`; a discount needs `sales.price.override` within cap
or the Owner's approval; a price correction needs `sales.price.override`.

## 2. Paid return (ruling 2)

New consignment status `paid_return`. Goods come back exactly as a return (sales return of the challan, no invoice,
no goods revenue). The money collected: Dr 1145 (courier money account) / Cr 4300 delivery income, journal sourced
on the consignment. Collected must be above zero (otherwise it is an ordinary return). The courier prompt for a
paid return now preselects it.

## 3. Lost or damaged parcel claim (ruling 5)

New table `courier_loss_claims`: consignment (one claim per parcel), courier money account, kind lost|damaged,
value at cost, claimed amount, compensation received, status open|accepted|paid|rejected, notes, the sales return,
the statement line or bank journal that paid it.

Reporting a loss closes the parcel as `lost` / `damaged`: the challan's goods come back on paper through a sales
return to stock (Dr 1150 / Cr 1155 at the layer cost the dispatch took), then leave again through an inventory
adjustment of type `lost_or_stolen` / `damaged` per line (the existing workflow: approval, FIFO cost, Dr loss /
Cr 1150). Compensation:
- on a courier statement (v2 already credits 4960 naming the courier): posting links the line to the claim by
  consignment and closes it as paid;
- paid outside a statement: "Compensation received" posts Dr bank / Cr 4960 with the courier's money account on
  the 4960 line, and closes the claim. Accepted and rejected are recorded with a note.

## 4. Partial delivery money

The existing per-line split stays. The resolution types above apply when the amount differs; tests cover a partial
delivery whose share of an order discount leaves paisa, settled as rounding.

## 5. Late events and cut-off (risk 9)

`AccountingPeriod::firstOpenOnOrAfter($date)`: the date itself when open, else the day after `closed_through`
(refused only when that is still in the future). An outcome, a loss claim, a manual compensation or a statement
whose own date lies in a closed period is posted on that first open day; the documents keep the courier's date
(`outcome_on`, `received_on`) beside `posted_on` and a `cutoff_note`, shown on the order, the parcels list and the
statement. Nothing is ever written into the locked period.

## 6. Reconciliation equation per statement (risk 4)

`collections - net fees - COD fees - additional + compensation + adjustment = payout`, for the whole file and for
this business's share, shown on the statement screen with the difference. A difference on the included rows blocks
posting (otherwise `LedgerRounding` would plug it silently).

## 7. Data model

- `consignments`: `door_resolution`, `door_difference`, `door_reason`, `posted_on`, `cutoff_note`,
  `party_adjustment_id`.
- `courier_door_discount_requests` (Approvable): consignment, payload of the outcome, amounts, reason, applied.
- `courier_loss_claims` as above.
- `courier_statements`: `posted_on`, `cutoff_note`.
- Party adjustment kind `rounding`.

## 8. Tests

`tests/Feature/Sales/Couriers/CourierSettlementHardeningTest.php`: each door resolution (cap respected, Owner
approval above cap then applied, short collection leaves AR, rounding both ways), paid return journals, loss claim
write-off + statement compensation closes it + manual compensation, partial delivery with paisa, closed period
handling for outcome and statement, equation flags, permissions. Existing courier tests kept green; the statement
closed-period test changes from "refused" to "posted in the first open period with a note".
