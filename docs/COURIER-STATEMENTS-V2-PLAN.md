# Courier statements v2: import and settlement hardening (courier hub slices 7 + 8)

Source: courier hub plan sections D, F, G; ChatGPT rulings 4, 5, 6, 7, 13 and risks 4, 5, 7, 9; owner decisions of 11 Oct 2026 (two new accounts approved).

## 1. Courier identity
- A statement's courier is the courier money account. Its provider comes from Settings > Couriers: `courier_account_business.money_account_id` -> `courier_accounts.courier_provider_id` -> `courier_providers.code`.
- An unlinked money account falls back to its free-text `provider`, normalised (`Pathao Courier`, `pathao `, `PATHAO` all become `pathao`; `Carry Bee` becomes `carrybee`).
- Claim keys use the normalised code, so one parcel can no longer be claimed twice under two spellings. Existing claim keys are normalised by the migration.

## 2. Formats
- `PathaoStatementFormat`: Promo_Discount and Compensation_Cost are accepted and stored. The row must add up either with them (fee net of promo, payout plus compensation) or without them (informational columns); a row that adds up neither way is a row error.
- `CarryBeeStatementFormat` (new): invoice CSV from the merchant portal. Recipient name and phone are never read into the ERP. Weight (hub kg) is stored as grams. Checks: Total Fee = Delivery - Discount + COD + Additional; Billing = Collected - Total Fee + Compensation + Adjustment. The file's Invoice_number fills the statement reference when none is typed, and a typed one must agree.
- Both normalise to one line shape: gross fee, discount, net fee, COD fee, additional, compensation, adjustment, payout, hub weight, delivery status.

## 3. Import pipeline
- The original bytes are kept on the private `local` disk; the path is on the statement.
- SHA-256 is unique per courier money account: the same file again is refused, naming the first statement.
- A row that cannot be read is collected as a row error (row, consignment, message); the rest of the file is imported. A statement with row errors cannot be posted. Error report CSV is downloadable.
- Header-level problems (empty file, wrong columns) still refuse the whole file.

## 4. Matching (computed on read, frozen at posting)
1. Consignment number (case-insensitive) against this business's parcels on this courier.
2. Merchant order id (leading `#` ignored) against the parcel's challan number, its order number or its shop reference. Ambiguous = no match.
3. Amount + date only as a suggestion text on an unmatched line. Never automatic.

## 5. Accounting (posting a reviewed, problem-free statement; only through PostJournal)
- Cr 1145 collected (courier money account on the line).
- Dr courier.delivery_fees / courier.return_fees at the NET fee (gross - discount); Dr courier.cod_fees; Dr courier.additional_charges.
- Adjustment: courier.additional_charges, debit or credit by sign (a positive adjustment is money to us).
- Compensation: Cr NEW 4960 Courier Compensation Income (`courier_compensation_income`, mapping `courier.compensation`).
- Net below zero with nothing paid out: Cr NEW 2165 Payable to Couriers (`courier_payable`, mapping `courier.payable`), not a credit left in 1145.
- "Settle against payable": a later payout may net off the courier's open payable for that courier (Dr 2165), and the deposit expected falls by the same amount.
- A deposit different from what is expected needs a written reason. A shortfall stays on 1145 (held back by the courier); an excess goes to 2165. Bank reference and an optional attachment are recorded on the statement.
- Every line on 1145, 2165 and 4960 carries the courier money account. PostJournal refuses a 2165 or 4960 line without a courier money account.
- Period locks: posting date must be in an open period (AccountingPeriod::assertOpenFor). A posted journal is never edited.
- Accounts are inserted by a migration for businesses that already have `sales.revenue` (pattern of 2026_09_14_110000), and by the seeders on a fresh database.

## 6. Exception workbook (XLSX, PhpSpreadsheet)
Summary, Matched, Differences, Unmatched in file, Missing from file (this courier's answered parcels on no posted statement after `finance_policies.courier_settlement_alert_days`), Errors. No recipient names or phones anywhere.

## 7. Screens
Index: upload with optional reference (CarryBee fills it from the file). Show: row errors with CSV download, rows with match method and suggestions, differences with reasons, compensation/adjustment/payable in the summary, post form with deposit, settle against payable, difference reason, bank reference, attachment; download workbook and original file. Permissions unchanged: sales.reports.view to view, sales.receipt.record to import and post (original file download needs sales.receipt.record because it holds customer data).

## 8. Tests
CarryBee arithmetic and PII dropped; Pathao promo/compensation accepted; same file twice refused; row errors collected; merchant-ref matching; net fee posting; compensation to 4960; negative statement to 2165 with the courier money account on every line; settle against payable; deposit difference needs a reason; two couriers' balances separate; period lock respected; existing CourierStatementTest kept green (updated where v2 deliberately changes behaviour).
