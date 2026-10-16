/**
 * Where each widget lands on the calm, role-aware dashboard (round 3, 16 Oct
 * 2026), run for real against components/dashboards/arrangement.ts. Read by
 * DashboardArrangementTest.
 *
 * Input (stdin): a JSON list of { dashboard, widgets: [{ key, category }] }.
 * Output (stdout): per call, what each block draws.
 */
import { detailGroups, KEY_RECIPES, NEEDS_ROW_WIDGETS, NEEDS_WIDGETS, placedKeys } from '@/components/dashboards/arrangement';
import { readFileSync } from 'node:fs';

type Call = { dashboard: string; widgets: { key: string; category: string }[] };

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];

process.stdout.write(
    JSON.stringify(
        calls.map(({ dashboard, widgets }) => {
            const present = new Set(widgets.map((w) => w.key));

            return {
                needs: [...(NEEDS_WIDGETS[dashboard] ?? []), ...(NEEDS_ROW_WIDGETS[dashboard] ?? []).map((w) => w.key)].filter(
                    (key, index, all) => present.has(key) && all.indexOf(key) === index,
                ),
                key: (KEY_RECIPES[dashboard] ?? []).map((r) => r.key).filter((key) => present.has(key)),
                groups: detailGroups(widgets, dashboard),
                placed: placedKeys(widgets, dashboard),
            };
        }),
    ),
);
