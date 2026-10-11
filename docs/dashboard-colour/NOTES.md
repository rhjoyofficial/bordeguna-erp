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
