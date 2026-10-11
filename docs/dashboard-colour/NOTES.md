# Role dashboards in colour

Joy, 11 Oct 2026: "make sure dashboard looks colorful, so the main area catch eyes."

Purely presentational. No data, permission, endpoint or behaviour changes.

## Plan

1. Palette tokens in `resources/css/app.css`, light and dark:
   - hero gradient from the brand leaf green through teal to deep blue;
   - one accent per domain (money emerald, sales indigo, couriers amber,
     stock sky, people rose, attention orange-red, me violet);
   - the validated dataviz categorical palette for charts;
   - fixed status colours (good, warning, serious, critical, neutral).
2. Hero band at the top of every role dashboard: three or four headline
   figures taken from the widgets already on the page (same live entries,
   no new query), large count-up numbers, a comparison chip with an arrow
   and words, a sparkline only where a real series exists.
3. Widget cards: accent per domain, icon tile, tinted top wash, hover lift,
   a short pulse when a live refresh changes the figure.
4. Charts: categorical palette in fixed order, gradient areas, rounded bars,
   donut with a centre total, courier states coloured by meaning.
5. Lists: status pills, zebra rows, initial chips for people, brand swatches
   on the brand switcher.
6. Reduced motion respected everywhere; AA contrast in both modes; 390 px
   stacks with no horizontal scroll.
7. Visual check on a scratch database, screenshots in this folder.

## What changed (11 Oct 2026)

- **Hero band** on every role dashboard: brand green into teal into deep
  blue, the dashboard's name, what the figures cover (range and brand), and
  up to four headline tiles. Owner: cash and equivalents, sales income (with a
  sparkline of the range), money with couriers and the gateway, needs
  attention. Finance swaps attention for unmatched bank lines; Manager, Sales
  and Employee have their own four. Each tile reads the widget already on the
  page (no new query, nothing a person may not see), counts to new values,
  carries a white chip with arrow and percent (green when the change is good
  news for that figure, red when not), and opens the screen behind it.
  Cash shows its split (cash, bank, wallet) as one bar; zero waiting reads
  "All clear, nothing waiting"; a failed widget reads "Could not load".
- **Cards**: one accent per domain (money emerald, sales indigo, couriers
  amber, stock sky, people rose, attention red-orange, me violet) as a top
  stripe, a gradient icon tile and a soft wash; lift and tinted glow on hover;
  one accent pulse when a live refresh changes the figure.
- **Charts**: the dataviz validated categorical palette (checked on the card
  surface in both modes), gradient areas with a dashed crosshair, rounded bars,
  bars drawn in once, donut with the total in the middle and percent per part.
  Courier and order states are coloured by meaning: delivered green, on the
  way blue, failed red, waiting amber, returned slate.
- **Lists**: status pills with a dot and the word, initial chips for people,
  zebra rows with a tinted hover. Brand switcher buttons carry a brand swatch
  (Bionic green, LaLaDia orange: an assumption, one map in `palette.ts`).
- **Empty states** are tinted, with an icon and the words, never grey boxes.
- **Contrast**: tone text moved from the 600 to the 700 steps in light mode
  (the 600 amber and emerald were under 4.5:1 on the card).
- Motion stands down under prefers-reduced-motion; numbers are never delayed.

## How the screenshots were made

Scratch SQLite database (`migrate`, `db:seed`), plus a throwaway seeder
(deleted, never committed) posting 61 days of demo ledger lines to LaLaDia:
daily sales split across cash, bank, wallet, gateway and couriers, cost of
goods, courier settlements, purchases and conveyance. Signed in as the seeded
local superadmin through a server-side session; no password typed. Orders,
attendance and payslips were not seeded, so those widgets show their empty
states (which is also how the empty states were checked).

- `owner-light-1440.png`, `finance-dark-1440.png`, `employee-dark-1440.png`,
  `owner-light-390.png` (first screen of each).
- No horizontal scroll at 390 px on any of the five dashboards, light or dark.

## Second pass: more colour (11 Oct 2026, after review)

- Hero tiles each wear their own colour on the brand band: cash emerald,
  sales indigo, couriers amber, attention rose (emerald when nothing is
  waiting), stock sky, people and approvals violet or rose. Tinted glass,
  a lit top edge, a solid icon tile, and the sparkline and split bar in the
  tile's own pale accent. The change chip keeps green for good and red for
  bad, so its meaning is not lost to the tile colour.
- Accents are now named by colour (emerald, teal, sky, indigo, violet, amber,
  rose, red) and chosen per widget in `palette.ts`: money in transit amber,
  gross profit violet, receivables teal, payables rose, bank lines sky,
  sales indigo, stock sky, people rose. A Needs attention card with nothing
  waiting turns emerald.
- Stronger card tint, a coloured border, and mini stat tiles filled in the
  accent with the label in the accent's 800 step (light) or 300 step (dark),
  so they stay AA.
- Merged master: every new string goes through `t()` with a Bangla entry
  (`dash.hero.*`, `dash.colour.*` in `lib/i18n/dashboards.ts`); the new Needs
  attention widget feeds the hero's attention tile and shows its severity
  counts as coloured pills.
