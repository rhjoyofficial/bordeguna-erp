# Courier locations (courier hub slice 2)

Goal (plan H.1 item 3, ruling 15 step 4): the ERP never books a parcel to a
guessed destination. A customer picks a place from Bangladesh's own list
(`BdGeo`: district, thana, area); each courier's own ids are looked up FROM
that place through a reviewed map. Pathao already misrouted 6 of 11 real
orders when it was left to read the address text itself.

## Data

- `courier_locations` (provider-wide, never business-scoped): provider code,
  level `city|zone|area`, `provider_location_id`, `parent_provider_id`, name,
  `extra` json (Pathao areas: `home_delivery_available`, `pickup_available`),
  `synced_at`, `children_synced_at`, `removed_at` (seen before, missing from the
  latest list). Unique (provider, level, provider_location_id).
- `courier_location_syncs`: one row per sync run (provider, account, status
  running|done|failed, counts, last error, started/finished). A run that did not
  finish is resumed: parents whose children were fetched after the run started
  are skipped.
- `courier_location_maps`: provider, district, thana, area (`''` = the whole
  thana, because NULL defeats a unique index on MySQL), provider city / zone /
  area ids and names, `status` applied|suggested, `source`
  auto_exact|auto_suggested|manual|api_suggestion, `confidence` high|medium|low,
  `candidates` json (the shortlist when there is no single answer),
  `reviewed_by` / `reviewed_at`. Unique (provider, district, thana, area).
- `courier_zone_bands`: provider, code, name, districts json, thanas json
  (`District/Thana`), `is_default` (the catch-all), sort order. Seeded in the
  migration: Pathao Inside Dhaka / Suburbs (Gazipur, Narayanganj districts;
  Dhaka/Keraniganj and Dhaka/Savar thanas) / Outside Dhaka; CarryBee Dhaka /
  Gazipur and Narayanganj / Outside Dhaka. A thana rule beats a district rule.

## Sync

Drivers gain read-only list calls through `CourierApi` (logged, URL allow-list
re-checked): CarryBee cities, zones, areas, area-suggestion, address-details;
Pathao city-list, zone-list, area-list. `couriers:sync-locations {account}`
and the "Sync now" button dispatch `SyncCourierLocations` for an ACTIVE
account. Paced (`couriers.location_sync_delay_ms`), a bounded number of calls per
job run, then it re-dispatches itself until done; at the end the matcher runs.
Upsert by the unique key, so a second sync changes nothing.

## Matcher

`CourierLocationMatcher` walks every BdGeo thana. Names are normalised
(lowercase, punctuation to spaces, spelling variants such as Chittagong /
Chattogram, Bogra / Bogura, Jessore / Jashore, Comilla / Cumilla, Barisal /
Barishal folded to one token). District to city: BdGeo's own resolver (aliases
and loose key; 64 names, proven collision free). Thana to zone inside that city
only: equal normalised name = high (auto_exact, applied); a single zone that
starts with or contains the thana as whole words, or equal by loose spelling =
medium (suggested); several = low (suggested, with candidates). Areas are not
auto-matched: an area map is added by hand when a thana needs one.
⚠ A re-run never touches a manual or reviewed map.

## Resolver

`CourierDestination::resolve(provider, district, thana, area)` returns
`ResolvedDestination` (city / zone / area ids and names, band, map id, source)
or `Unresolved` (reason code + sentence): unknown place, not mapped, awaiting
review, location removed by the courier, area without home delivery.

## Screen

Settings > Couriers > Locations (`couriers.settings.manage`): per provider,
counts (synced, mapped thanas, needing review, unmapped), Sync now, Match again,
a review table (needs review / unmapped / mapped, search) with a SearchSelect
of the city's zones and areas, CarryBee's area suggestion as a hint, approve
one, approve all high, edit any map. Zone bands editable on the same screen.

## Tests

Http::fake only: idempotent sync, resume, matcher (aliases, contained names,
never applies low or medium), resolver (unmapped, awaiting review, removed,
band precedence), review approve stamps manual + reviewer, permission gating,
bands seeded and editable.
