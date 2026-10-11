# Shop order import and bulk courier booking (plan)

Owner decisions, Joy, 11 Oct 2026 (courier-hub-2026-10/CHATGPT-RULINGS-2026-10-11.md): every
LaLaDia and Bionic order is recorded in the Bor de Guna business with its brand; until the ERP
website module is ready, shop orders reach the ERP typed by hand or from an IMPORT SHEET; parcel
weights are worked out from the lines and stay directly editable.

## 1. Shop order import (Sales > Orders > Import orders)

One row per order LINE, grouped by `order_ref` (the shop's order number, for example
`#LLD-20261010-0001`). Order-level columns may repeat on every line or sit on the first line only;
a later line that disagrees is an error, never a silent pick.

Template columns, in order (XLSX with a Help sheet, or CSV with a UTF-8 BOM):

| column | level | rule |
|---|---|---|
| order_ref | order | required, at most 40 characters; stored as `sales_orders.shop_reference` |
| order_date | order | required; 2026-10-10, 10/10/2026 (day first) or an Excel date |
| brand | order | name or code of an active brand (LaLaDia / LLD, Bionic-Garden / BNC); required when the business has brands |
| channel | order | website, facebook, whatsapp, phone, other (blank = other) |
| customer_name | order | required |
| phone | order | required; normalised to 01XXXXXXXXX or refused |
| alt_phone | order | optional; normalised; kept in the order note |
| district, thana | order | required; BdGeo names and aliases (Chittagong = Chattogram) |
| area | order | optional; an unknown area is dropped with a warning (the checkout's rule) |
| address_line | order | required |
| landmark | order | optional |
| sku | line | required; an active stocked product of this business |
| quantity | line | required, greater than zero |
| unit_price | line | optional; blank = the price list. Different from the list = a price override: needs `sales.price.override`, kept on the line as "Imported from shop" with the list price |
| line_discount | line | optional, not more than the line |
| order_discount | order | optional, not more than the goods |
| delivery_charge | order | optional |
| advance_paid, advance_method, advance_reference | order | optional; method bkash, nagad, rocket, upay, bank, card, cash or other. Becomes an order advance receipt ONLY when the preview screen maps that method to a money account (needs `sales.receipt.record`); otherwise it is written into the order note |
| payment_method | order | cod or prepaid (blank = cod) |
| note | order | optional |
| weight_grams | order | optional; a typed parcel weight (blank = worked out from the lines) |

Checks per row and per order: unknown SKU, unknown district or thana, phone, quantity, price
without permission, missing price, discounts, advance more than the order, order_ref already
imported (skipped with a link to the order, so a re-sent file is idempotent), the same file already
imported (refused with a link to that import), disagreeing order-level values.

Customers are matched by normalised phone (first active, non-system customer of the business);
otherwise a new Retail customer is created. A brand whose business has a store linked to it prices
the order at that store's price group, as the website would; otherwise the customer's own group.

Apply re-reads the file from disk, checks the SHA-256 the preview showed, re-plans and refuses
on any error. Each order is its own transaction: CreateSalesOrder (approval request, auto weight,
address, delivery charge, discounts, and the brand through its `brandId`, which refuses a brand of
another business or one switched off; the brand is also kept on the import row), then the
advance through RecordOrderPayment. "Auto-approve imported orders" is offered only to a holder of
`sales.orders.approve` and goes through ApproveSalesOrder (credit rules apply; a refused one stays
waiting). Otherwise approvers get ONE notification for the whole file.

Data: `sales_order_imports` (file kept on the private disk, SHA-256 unique per business, counts,
options, who) and `sales_order_import_orders` (order_ref, order, brand id and name, channel,
outcome created / skipped / failed, message).

## 2. Parcels to book (Sales > Parcels to book)

The list: approved orders of this business that have a delivery address and no parcel yet, either
with nothing delivered (a challan will be made for everything still ordered) or with one challan
that no courier has (it is booked as it is). Per row: brand, destination, COD the courier would
collect, weight, stock in the chosen warehouse, and any booking whose answer is still open.

One click on the selected rows ("Create challans and book"): a `courier_bulk_runs` row and one
`courier_bulk_run_items` row per order, then a queued job (BookParcelsInBulk) that, per order:

1. refuses an order already in another unfinished run, or already handed to a courier;
2. checks the address and the courier destination BEFORE any stock moves; an unresolved
   destination becomes "fix address" (linked to Couriers > Locations) and no challan is made;
3. checks stock per line in the warehouse and names the product that is short;
4. creates the challan through RecordDelivery for everything still ordered;
5. books it through BookParcelThroughCourier (the intent row, one in flight per challan, the
   lock, the estimate), COD = what the challan would bill rounded up, weight = the order's;
6. records booked (consignment and quoted fee), refused (reason) or unknown (settle on the order).

Courier account: chosen on the screen, or "default for the brand": the account the order screen
preselects for the order's brand (ParcelPresenter::accountOptions over
BookParcelThroughCourier::linkFor), so bulk booking has no routing rule of its own. An account kept
for the other brand is shown unavailable with its reason, and refused by the booking action. Calls are paced (300 ms, `couriers.bulk_pace_ms`). Progress is
broadcast as RecordChanged (kind `courier_bulk_run`) and also served as JSON for polling.

## 3. Labels

A4 print page for the booked parcels of a run (or chosen parcels): 2 or 4 per page, consignment
number with a QR code (bacon-qr-code, the asset tags' encoder) and a Code 128 barcode (the product
labels' encoder), recipient, phone, address, COD, brand, order and shop reference.

## 4. Permissions

Import: `sales.orders.record` (+ `sales.price.override` for changed prices, `sales.receipt.record`
to record advances, `sales.orders.approve` to auto-approve). Bulk booking and labels:
`couriers.parcels.book` + `sales.sell`.

## 5. Tests

Template download (both formats); preview refuses each error type; apply creates customers,
orders, addresses, lines, delivery charge, advance and weight, and is idempotent by order_ref and by
file hash; brand handled with and without `sales_orders.brand_id`; bulk booking creates challans and
books with Http faked, per-row results, stock refusal, unresolved destination, no double booking;
labels render; permissions.
