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

## Third pass: back to the calm look, simple colour (11 Oct 2026)

Joy, on the second pass: "Previous looks was good, just it their used some
simple coloring will make it perfect." So the first-pass look is back and
colour sits only on small marks:

- Hero: one calm green band, neutral glass tiles, white numbers, white
  sparkline and split bars. Each tile's only colour is its icon, in the
  domain's 700 step on a white disc (`--acc-strong`), plus the green or red
  change chip.
- Cards: plain card background and border, no tint, no coloured border, no
  coloured hover glow. Domain colour only on the 3px top stripe, the icon
  tile, chart series and status pills.
- Mini stat tiles and empty notes are neutral (muted grey, dark text); the
  empty note keeps a coloured icon. `--acc-wash` is back to a faint 9% (12%
  dark), used only for row hovers; `--acc-tile` is gone.
- Kept from the second pass: colour-named accents and the per-widget map in
  `palette.ts`, every translation, Needs attention (widget, hero tile,
  severity pills), brand switcher, live refresh, report charts on `SERIES`.

Screenshots (scratch SQLite, 61 days of demo ledger lines, session signed
server side): `round3-owner-light-1440.png`, `round3-owner-dark-1440.png`,
`round3-finance-light-1440.png`, `round3-owner-light-390.png`. No horizontal
scroll at 390 px.

## Fourth pass: simpler colour, minimal hero, recorded sounds (11 Oct 2026)

Joy, on the third pass: "i like it, but no need the each part top border
color, also improve and simplify the finance part colors, no needs on hover
movements full card, enhance, simplify overall colors, no needs full card bg
colors, also first part can but minimal and futureistic".

- Cards: no top stripe, plain surface, a static hairline border, no lift,
  scale or glow on hover. Icon tiles are neutral; only a card whose figure
  needs someone (attention waiting, a warn or bad tone, a failed load) turns
  its icon tile amber or red.
- One colour system: the ERP brand green for links and the live pulse
  (`.dash-acc-brand`), neutral greys for structure, two chart colours (blue
  in and trend, orange out; `chartColour`), donut parts as steps of the one
  blue (`shareColour`), semantic green, amber and red only where they mean
  something. The per-domain accents and `accentFor` are gone; `SERIES` stays
  for reports. Ageing rows run neutral, amber, red on the dots and bars, and
  the values stay in the normal text colour while the bucket is empty.
  Status pills are softer; people chips are neutral; signal rows are neutral
  with a small amber count or green tick.
- Hero: deep green to near black in both modes, one faint light pool, a
  hairline grid fading from the top right, hairline glass tiles, small
  spaced uppercase labels, light large tabular figures, monochrome icons, a
  thin softly glowing trend line, a green or red change chip. Hover only
  brightens a tile's edge.
- Sounds: `public/sounds/success.mp3`, `attention.mp3`, `error.mp3`, decoded
  once after the first click or key press, at the person's volume, with the
  synthesised tones as the fallback (sources and licence assumption in
  `lib/dashboard-sound.ts`). All earlier rules kept; each class has its Test
  button in the sound settings.

Screenshots: `round4-owner-light-1440.png`, `round4-owner-dark-1440.png`,
`round4-finance-light-1440.png`, `round4-owner-light-390.png`.
