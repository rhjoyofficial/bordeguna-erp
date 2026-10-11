# Order brand and courier routing by brand (plan)

Owner decision, Joy, 11 Oct 2026 (courier-hub-2026-10/CHATGPT-RULINGS-2026-10-11.md, "Bor de Guna + brands"):
every LaLaDia and Bionic order, parcel, COD and bank movement is recorded in the Bor de Guna business,
and each order carries its BRAND so sales, campaigns, parcels and profit can be filtered per brand.

Production shape: Bor de Guna = business 4, brands 1 LaLaDia (LLD) and 2 Bionic-Garden (BNC).
Courier links of business 4: CarryBee "LaLaDia" (store Adabor 2), CarryBee "Bionic" (store Bionic),
Pathao (both brands). Both CarryBee links are `is_default` today, which is ambiguous.

## 1. The order carries a brand

- `sales_orders.brand_id`, nullable FK to `brands` (restrict on delete, like products), index
  `(business_id, brand_id)`. Existing orders stay null and are filtered as "No brand".
- Who sets it:
  - website and HQ orders: `SalesOrderOrigin::website()` copies `stores.brand_id`; a multi-brand
    store (brand null) leaves the order null;
  - manual entry (orders/index.tsx): a Brand select, REQUIRED when the business has active brands,
    pre-filled with the brand of the user's own last order that had one (derived, no new column);
  - quotation accept and the counter: unchanged (null), named as a judgement call.
- `CreateSalesOrder` takes `brandId` (explicit, else the origin's) and refuses a brand of another
  business or an inactive one.
- Shown: order list (badge + Brand filter with "No brand"), order page, deliveries list, parcels
  list (filter), invoices list (derived through invoice line -> challan line -> order line -> order).
- Sales by brand report: untouched (it reads product brands from invoice lines).

## 2. Courier routing by brand

- `courier_account_business.brand_id`, nullable FK: "this link serves orders of this brand",
  null = any brand. Unique `(courier_account_id, business_id, brand_id)` replaces
  `(courier_account_id, business_id)`; the new unique is created BEFORE the old one is dropped so
  MySQL always has an index leading on `courier_account_id` for its FK (error 1553 otherwise).
  MySQL treats NULLs as distinct, so "one any-brand link per account per business" is kept by the
  writer (updateOrCreate on brand_id null).
- One account may hold several links in one business (one per brand, plus any-brand).
- Default: one per (business, provider, brand), brand null being its own bucket.
- Which link serves an order: the link for the order's brand, else the any-brand link. An order
  with no brand (old orders) uses the any-brand link, else the account's only link; an account with
  several brand links and no any-brand link refuses a brandless order ("set the brand").
- Booking form: every bookable account is listed; one linked only to another brand is disabled with
  the reason, and the server refuses it too (`BookParcelThroughCourier::linkFor` with the brand).
  Pre-selected: the default link for the order's brand, then any link for that brand, then the
  any-brand default, then the first available.
- Settings > Couriers: the business-link section lists the links (brand, store, money account,
  default) and its form gets a Brand select ("Any brand" or a brand).

## 3. Brand on parcels

Derived, no column: consignment -> delivery -> delivery lines -> order lines -> order.brand_id.
One helper (`Sales\Support\OrderBrand`) holds the filters for orders, challans, parcels and invoices
and the label lookup, all as `whereExists` subqueries with integer bindings (MySQL strict mode).

## 4. Dashboards

- `WidgetContext::$brandId` (null = all brands) from URL param `brand`, accepted only when it is a
  brand of the current business, else ignored.
- `Widget::brandAware()` (default false). Brand-aware: orders by state, my orders, orders needing
  action, orders to dispatch, top products, parcels by courier status, parcels needing attention,
  COD chain. The cache key carries the brand for those only. Ledger and HR widgets stay whole-business
  and their card says "All brands" while a brand is chosen.
- The dashboard gets a Brand switcher (All brands / each brand) next to the date range, kept in the
  URL with the range. Authorisation unchanged.

## 5. Data migration

In every business that has exactly two CarryBee links whose account labels are "LaLaDia" and
"Bionic" (case-insensitive, contained), set those links' brand_id to the brand whose code is LLD /
name contains LaLaDia, and BNC / name contains Bionic, both brands in that business. Pathao and any
other link stay any-brand. Anything else: skip silently. Then clear `is_default` ambiguity: each
CarryBee link becomes the default of its own brand bucket (they no longer collide).

## 6. Tests

Brand required on manual entry when brands exist; website order gets the store brand; booking picks
the brand's account and refuses one linked to the other brand; settings link with brand and
per-brand default; brand filters on orders/parcels/deliveries/invoices; dashboard brand filter and
cache separation; data migration matching and skip rules; business isolation of the brand id.
