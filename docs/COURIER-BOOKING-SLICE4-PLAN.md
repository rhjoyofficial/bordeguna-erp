# Courier booking and status (courier hub slice 4)

Goal (plan J slice 4, ruling 15 step 6): book a parcel through the courier's
API from the order screen, so nobody types it twice, and follow it by webhook
and by a 15-minute pull. Money stays with a person (ruling 1): a courier saying
"delivered" only offers the existing outcome form, prefilled.

## Data

- `consignments` gains: `courier_account_id`, `provider_consignment_id`,
  `merchant_order_ref`, `booked_via` api|manual, `provider_fee_quoted`,
  `provider_cod_fee_quoted`, `courier_status` (canonical, plan C.3),
  `courier_status_raw`, `status_synced_at`, `courier_city_id` / `zone_id` /
  `area_id`, `hub_weight_grams`, `courier_collected_amount`,
  `courier_invoice_ref`. Unique (courier_account_id, consignment_number).
- `courier_booking_requests` (ruling 13): business, delivery, account,
  `merchant_order_id` (= the challan number, stable and unique), request hash,
  status requested|succeeded|failed|unknown, response summary WITHOUT PII,
  error, consignment, created_by, resolved_by/at. Written BEFORE the call.
- `courier_shipment_events`: consignment, source webhook|poll|booking, event,
  raw status, canonical status, occurred_at, payload hash, whether it moved
  the status, a short non-PII detail. Unique (consignment, event, occurred_at).
- `courier_accounts` gains `integration_secret` (encrypted, Pathao's
  account-level echo value) and `auto_post_outcomes` (default off; stored and
  shown disabled, "available after the evidence period"; emergency off works).

## Booking

`BookParcelThroughCourier`: account must be active and linked to this business
(store + courier money account); destination from `CourierDestination` on the
order's address (Unresolved refuses with the reason and a link to Locations);
phone normalised to 01XXXXXXXXX; COD whole taka (paisa refused, never rounded
down; the form offers the next whole taka and says why); weight in grams for
CarryBee, kg (0.1 up, at least 0.5) for Pathao; item description from the
challan lines. A cache lock per challan plus a check under the row lock makes a
double submit one booking. Result:

- 2xx with an id: consignment created through `BookConsignment` (all its guards).
- 4xx: `failed`, the reason shown, booking may be tried again.
- timeout / connection error / 5xx: `unknown`. NO automatic retry.
  CarryBee: "Check at CarryBee" looks the reference up (GET details by
  merchant_order_id): found = consignment recorded, 404 = marked failed, safe to
  book again. The 15-minute job runs the same check. Pathao has no reliable
  lookup: a person chooses "it was booked, number ___" or "it was not booked".

The manual "type a consignment number" path stays as a fallback.

## Status

Provider classes map raw words to canonical (CarryBee webhook events and detail
words; Pathao slugs). Unknown words are logged and kept raw, status unchanged.
A late event (older than the last applied one, or anything after a final
state) is recorded but moves nothing. Payment words (`order.paid`,
Payment_Invoice) keep the courier invoice ref only.

- Webhook `POST /webhooks/couriers/{uuid}` (no session, no CSRF, throttled):
  CarryBee header secret, `webhook.integration` handshake 202 + echo; Pathao
  X-PATHAO-Signature, echo X-Pathao-Merchant-Webhook-Integration-Secret. The
  parcel is found by OUR account + consignment id / merchant ref; the business
  comes from our record. Unknown parcels logged, 202. Never posts money.
- `couriers:sync-parcels` every 15 minutes: open parcels per active account,
  paced; manual "Refresh status" on the order page.
- Cancel (CarryBee API; Pathao unverified: tried, else "cancel in the Pathao
  panel"). A cancelled parcel is then recorded as returned through the outcome.
- `CourierParcelChanged` event (type + ids) for the dashboard slice.

## Permissions

Book and cancel: `couriers.parcels.book` + `sales.sell`. Parcel list, timeline
and refresh: `couriers.parcels.view` (or `sales.reports.view` for the list as
before). Outcome still needs `sales.receipt.record` / `sales.returns.record`.

## Tests

All HTTP faked with `Http::preventStrayRequests()`: CarryBee and Pathao field
mapping, unresolved refused, intent before call, timeout -> unknown -> lookup
recovers, Pathao no blind retry, double submit, unique number, cancel, webhook
secret + handshake + idempotent + late + business from our record, poll,
delivered does not post money, outcome still posts, permissions, no PII logged.
