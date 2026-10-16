/**
 * The top of the sidebar and the Workspace group (round 3, 16 Oct 2026), run
 * for real: what a person's shared flags draw, which tab row an address gets,
 * and what the header search finds. Read by WorkspaceSectionsTest.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { type NavGroup } from '@/components/nav-groups';
import { GROUP_SECTIONS, HOME_SECTIONS, WORKSPACE_SECTIONS } from '@/components/workspace/workspace-sections';
import { matchMenus } from '@/lib/menu-search';
import { ALL_SECTIONS, sectionNavItems, sectionSearchGroups, sectionTabRow, tabTitle, type Translate } from '@/lib/sections';
import { type SharedData } from '@/types';
import { readFileSync } from 'node:fs';

type Flags = Record<string, unknown>;

/** A stand-in translator: marks every key it was asked for. */
const fakeT: Translate = (key) => `«${key}»`;

type Call =
    | { op: 'sidebar'; flags: Flags; translate?: boolean }
    | { op: 'row'; path: string; flags: Flags; translate?: boolean }
    | { op: 'search'; q: string; flags: Flags }
    | { op: 'titleKeys' };

/** The two drawn groups exactly as app-sidebar builds them. */
function drawn(flags: SharedData, t?: Translate): NavGroup[] {
    return [
        { label: 'Home', hideLabel: true, items: [...sectionNavItems(HOME_SECTIONS, flags, t), ...sectionNavItems(GROUP_SECTIONS, flags, t)] },
        { label: 'Workspace', items: sectionNavItems(WORKSPACE_SECTIONS, flags, t) },
    ];
}

function run(call: Call): unknown {
    switch (call.op) {
        case 'sidebar':
            return drawn(call.flags as SharedData, call.translate ? fakeT : undefined).map((group) => ({
                label: group.label,
                items: group.items.map(({ title, url, badge }) => ({ title, url, badge: badge ?? null })),
            }));
        case 'row': {
            const t = call.translate ? fakeT : undefined;
            const row = sectionTabRow(call.path, call.flags as SharedData, ALL_SECTIONS);
            return row
                ? {
                      section: row.section.key,
                      tabs: row.tabs.map((tab) => tabTitle(tab, t)),
                      urls: row.tabs.map((tab) => tab.url),
                      current: row.current.url,
                  }
                : null;
        }
        case 'search': {
            const flags = call.flags as SharedData;
            const groups: NavGroup[] = [
                ...drawn(flags),
                ...sectionSearchGroups(
                    [
                        ['Home', HOME_SECTIONS],
                        ['Workspace', WORKSPACE_SECTIONS],
                        ['All businesses', GROUP_SECTIONS],
                    ],
                    flags,
                ),
            ];
            return matchMenus(groups, call.q);
        }
        case 'titleKeys':
            return [...HOME_SECTIONS, ...WORKSPACE_SECTIONS, ...GROUP_SECTIONS].flatMap((section) => [
                ...(section.titleKey ? [section.titleKey] : []),
                ...section.tabs.flatMap((tab) => (tab.titleKey ? [tab.titleKey] : [])),
            ]);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
