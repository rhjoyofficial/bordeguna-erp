# Product weights and parcel weight (courier hub slice 3)

Owner decisions, 11 Oct 2026 (CHATGPT-RULINGS-2026-10-11.md, "Owner decisions" 1):
every product has a weight; the order (parcel) weight is worked out from the
lines and can be changed directly, with no reason asked; the worked-out value
is kept beside the changed one for later fee analysis.

## Data

- `products.weight_grams` (unsigned int, null = not known) and
  `products.weight_source` (`manual` | `from_net_content` | `learned`, null when
  there is no weight). `learned` is reserved for the later slice that suggests
  corrections from courier hub weights; nothing writes it yet.
- Data migration: products whose net content unit is a mass (g x1, kg x1000,
  matched on the same aliases the units seeder uses) get that weight with
  source `from_net_content`. Liquids (L, mL) and counts stay empty: no density
  guess. Reversible (drops the columns).
- `sales_orders.weight_grams_auto` (Σ quantity x product weight at order time,
  null when any line has no weight: missing weight is never zero, ruling 10),
  `sales_orders.weight_grams` (the typed value, null = use auto),
  `weight_edited_by` / `weight_edited_at` (silent record of who typed it).
- `consignments.declared_weight_auto_grams` (Σ challan quantity x product
  weight, worked out by the server at booking) and
  `consignments.declared_weight_grams` (what was told to the courier).

## One calculation

`Sales\Support\ParcelWeight` computes the auto weight for an order (ordered
quantities) or a challan (delivered quantities, so a part shipment weighs only
its part). It returns the known total, whether it is complete, and the lines
missing a weight. The booking form's prefill: the order's typed weight when the
challan carries the whole order, otherwise the challan's own auto weight.

## Screens

- Product dialog: "Weight (g)"; editing sets source `manual`. On create, a blank
  weight with a mass net content is filled from it (`from_net_content`); on
  update, a weight that came from net content follows a changed net content.
- Products list: weight column with a "weight missing" badge and filter.
- Catalogue CSV: optional `weight_grams` column (import and export).
- Weights sheet (`/inventory/products/weights`, catalogue managers): download
  a CSV (sku, name, net_content, weight_grams), fill it, upload, preview with
  row-level errors, apply. Blank weight = leave as is. Linked from Products and
  from the sidebar.
- Order entry: live auto total with "N item(s) without weight", and an editable
  weight box (no reason).
- Hand to courier (order page): weight prefilled, editable, stored on the
  consignment.

## Not in this slice

Packaging profiles, courier-hub learned weights and the suggestion screen,
rate cards. Order weight is set at entry and at booking; there is no separate
"edit order weight" screen after creation (the booking form is where the parcel
weight is confirmed).
