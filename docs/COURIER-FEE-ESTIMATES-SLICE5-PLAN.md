# Courier fee estimates, variance and calibration (courier hub slice 5)

Goal (plan section E, rulings 8, 9, 10 and the owner overrides of 11 Oct 2026):
say what a parcel will cost BEFORE it is booked, keep that figure, and learn
from what the courier really charged. Three separate immutable facts, never
one overwriting another:

1. **Estimate**: what the ERP worked out (this slice), snapshot at booking.
2. **Provider quote**: what the courier said at booking
   (`consignments.provider_fee_quoted` / `provider_cod_fee_quoted`, slice 4).
3. **Actual**: what the courier charged on its statement (statement line net
   fee + COD fee, statements v2).

The customer's delivery charge (the storefront band price) is revenue and is
never shown as, or mixed with, the courier fee (our cost).

## Data

- `courier_rate_cards`: provider, version label (unique per provider),
  effective_from, status draft | approved | retired, `is_list_price` (CarryBee's
  calculator is a list price, real charges are lower), source
  published | calibration | manual | import, based_on (the card a draft was
  copied or calibrated from), notes, created_by, approved_by / approved_at.
  Provider-wide like the zone bands (one card serves every business that books
  through that courier).
- `courier_rate_card_lines`: from_band, to_band (the courier's OWN band codes,
  `courier_zone_bands`), service normal | express | same_day, weight_from_g
  (exclusive) to weight_to_g (inclusive, null = open), price, extra per started
  kg after N g, return_fee_percent (a scenario, never in the main figure),
  expected_discount (null until the owner fills it from calibration; never
  invented), and for calibrated drafts the sample count and previous price.
- `courier_fee_estimates`: written once, never updated or deleted (model guard).
  Business, delivery, consignment (null before booking), account, provider,
  method provider_quote | rate_card | historical | insufficient, rate card id +
  version, from/to band, service, weight (grams) and bracket, COD amount,
  delivery fee, COD fee, total, confidence, sample size, basis text, inputs JSON.

Approved cards are frozen: their lines cannot change. A change is a new version.

## Seeded cards (approved, effective 2026-10-11, version v2026-10-11)

- **Pathao** from its Pricing Plan page (pathao-api-notes.md): brackets
  0-500, 500-1000, 1000-2000 g; above 2 kg +15 per kg to Inside Dhaka and +25
  per kg elsewhere; Same Day (Dhaka metro only) 120 / 150 (0.5-2 kg) +15 per kg;
  return fee 50%. "Same city" rows for Suburbs and Outside cannot be told apart
  by band, so the "different city" price is seeded (the higher one).
- **CarryBee LIST** from the sampled calculator: Dhaka 60/70/90/110/150,
  Gazipur + Narayanganj 85/100/125/150/190, outside 105/125/150/170/220 at
  0.5/1/2/3/5 kg. Nothing above 5 kg (not sampled: no line, so no guess).
  `is_list_price` = true and every `expected_discount` = null: the owner fills
  the discount once calibration shows it.

Pickup band: both pickup stores are in Dhaka (Adabor), so the from band is the
courier's band for `couriers.pickup_district` (config, default Dhaka).

## Estimator

`CourierFeeEstimator::estimate(account, business, destination, weightGrams,
codAmount, service, weight info)`:

0. Missing weight or an unresolved destination: **Insufficient data** with the
   reason. Never zero.
1. Pathao and the account is active: live `POST /aladdin/api/v1/merchant/price-plan`
   (store, item type, delivery type, kg, city, zone), cached 6 h per (account,
   city, zone, weight bracket, service). Confidence **High**. A failure falls
   through. At booking the cached quote is used if there is one, never a new
   call (the booking request must not wait on, or be broken by, a quote).
2. The approved card effective on the date for the provider: line by band,
   service and bracket, plus extra per started kg, less the expected discount
   when set. **Medium**; **Low** for a list-price line with no expected discount.
3. Historical model: median actual net fee from matched statement lines, same
   provider, band and bracket, at least 10 clean samples (excluded: cancelled,
   returned, unmatched, hub weight more than 20% from declared).
   **Medium** with 30 or more samples, else **Low**; sample size in the basis.
4. Otherwise **Insufficient data**.

COD fee = collectable x the account's `cod_percent` (Pathao 1, CarryBee 0.5);
a minimum only when the account options carry `cod_min_fee`.

Basis text, exactly: `Estimated charge Tk X. Basis: weight Y kg (n of m items
weighed), Dhaka -> Panchagarh (Outside Dhaka), Pathao rate card v2026-10-11,
COD 1% included. Confidence: Medium`.

## Screens

- **Booking form** (order screen): a live estimate under the form, refreshed as
  the account, weight, COD or delivery type change (debounced JSON GET
  `sales/deliveries/{delivery}/courier-estimate`, `couriers.parcels.view`).
  The same estimate is snapshot when the booking succeeds.
- **Sales > Courier fees** (`couriers.ratecards.propose`): per parcel estimate
  vs provider quote vs actual, filters (courier, band, weight bracket, dates),
  aggregates (count, mean absolute and % variance, median), outliers flagged
  (more than 25% off), excluded samples shown with the reason, sample size,
  Recharts bar (variance by band) and trend line (by week), tables for the
  exact numbers. Button: propose a calibrated card.
- **Settings > Couriers > Rate cards**: versions per provider, lines, edit a
  draft, new draft from the current card, CSV import into a draft and CSV
  export of any card, calibration proposal shown before/after, approve with an
  effective date (Owner), retire.

## Calibration (ruling 9)

Proposes a DRAFT version: for each line of the current approved card, the
median actual net fee of its clean samples when there are at least 10 (the
minimum candidate threshold, not proof), else the old price kept. Samples above
a line's extra-per-kg threshold are left out of that line. The draft shows
before, after and n per line; the Owner approves with an effective date (today
or later). Older estimates keep the version they used.

## Permissions

- `couriers.ratecards.propose`: Manager, Owner (rate cards screen, drafts,
  CSV, calibration proposal, the Courier fees report).
- `couriers.ratecards.approve`: Owner (approve, retire).
Granted by migration to existing roles and by `SalesPermissionsSeeder`.

## MySQL strict mode

Band codes, provider codes, service names and version labels are strings and
are always compared as strings (`(string)` casts in every query); weights and
prices are cast to int / decimal strings before they reach SQL.

## Tests

Each priority level; Pathao quote faked and cached; bracket + extra per kg +
same day; COD %; insufficient data (missing weight, unresolved); basis text;
snapshot immutable and written at booking; variance aggregates excluding bad
samples; calibration thresholds; approval effective date; permissions.
