# Courier settings, slice 1 (branch feature/courier-settings)

Source: courier-hub plan 11 Oct 2026 (sections G, H.1 item 3, I), ChatGPT ruling 11, risks 6 and 11.
Joy: "all settings must be available on UI so I can change them anytime".

## Scope (this slice only)
- Settings > Couriers at `/settings/couriers` (Owner only: `couriers.settings.manage`).
- No booking, no webhook endpoint, no money effect. Read-only provider calls.

## Code
- `app/Modules/Sales/Couriers/` : Models, Providers (registry + one class per provider), Drivers, Http controller, Console command.
- Migrations in `app/Modules/Sales/database/migrations` (2026_10_11_*).

## Tables
- `courier_providers`: code unique, name, is_connected (driver exists), sort. Seeded pathao, carrybee (connected); steadfast, redx, paperfly (not yet connected). Metadata lives in the code registry, not the row.
- `courier_accounts`: public uuid, provider code, label, environment sandbox|live, base_url (must be in the provider allow-list for that environment, checked server side), credentials encrypted:array, options json (validated against the provider schema), webhook_secret encrypted, token_cache encrypted, is_active, activated_at, credentials_fingerprint (hash of credentials + base URL at last test), last_test_at/ok/message, last_test_stores json, last_test_city_count, created_by. Not business-scoped.
- `courier_account_business`: account, business, provider_store_id/name, money_account_id (courier-type money account of THAT business), is_default; unique (account, business).
- `courier_api_calls`: account, method, endpoint path, http status, ms, error, created_at. Never secrets or bodies.

## Rules
- Credentials never leave the server after save (props carry "set" flags only). Blank on update = keep.
- Base URL is a select of official URLs; anything else refused (ChatGPT risk 6).
- Activation needs a passing test whose fingerprint equals the current credentials + base URL. Changing either deactivates.
- Pathao token cached per account id + environment, encrypted, until expiry.
- Permissions: `couriers.settings.manage` (Owner), `couriers.parcels.view` (Owner, Manager, Staff), `couriers.parcels.book` (Owner, Manager). Seeder + migration grant. Technical, not accounting authority (risk 11).
- Activity log on accounts: non-secret fields only.
- `couriers:import-credentials {path} --provider= --label= --environment= --prefix=` creates/updates an INACTIVE account, prints masked values.

## Tests
`tests/Feature/Sales/Couriers/*`: secrets at rest + props, blank update, allow-list, options, activation, credential change, Http::fake both providers, token cache, permissions, business link, api log, import command.
