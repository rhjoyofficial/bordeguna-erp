# QA walk of the new screens (11 Oct 2026)

Branch `fix/new-screens-qa` from master `7cbb7cf3`.

## Plan

1. Scratch SQLite database (`database/qa.sqlite`, gitignored): `migrate`, `db:seed`, then a
   throwaway script adds brands, weighed products, CarryBee + Pathao money accounts, CarryBee +
   Pathao courier accounts with FAKE credentials (base URL pointed at a closed local port, test
   fingerprint written so they can be active), location maps, an order with an address and an
   unbooked challan, booked parcels, a door discount above the cap, a delivered parcel and the
   sanitised CarryBee statement.
2. `npm run build`, `php artisan serve` on 127.0.0.1, sessions signed server-side (no password
   typed), headless Chrome through Playwright.
3. Every new screen at 1440 and 390 px as Owner and as Staff (Manager, Finance and Sales Officer
   for their dashboards): console errors, HTTP 4xx/5xx, horizontal scroll, four-decimal numbers,
   em dashes, NaN/undefined, empty states, dark mode.
4. Each defect fixed with the smallest correct change, a test where it is logic, one commit each.
5. This file then lists every screen, what was checked, what was fixed and what is left for a
   person.
