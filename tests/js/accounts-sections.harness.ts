/**
 * Accounts in one sidebar group (redesign round 3, 16 Oct 2026), RUN with a
 * role's real shared flags: which sidebar rows and which tabs the role gets,
 * which tab row an address draws, and what the header search finds. Read by
 * tests/Feature/Accounting/AccountsSectionsTest.php through tests/js/run-ts.mjs.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results.
 */
import { ACCOUNTS_SECTIONS } from '@/components/accounting/accounts-sections';
import { type NavGroup } from '@/components/nav-groups';
import { matchMenus } from '@/lib/menu-search';
import { sectionNavItems, sectionSearchGroups, sectionTabRow, visibleTabs } from '@/lib/sections';
import { type SharedData } from '@/types';
import { readFileSync } from 'node:fs';

type Flags = Record<string, unknown>;

type Call =
    | { op: 'nav'; flags: Flags }
    | { op: 'tabs'; flags: Flags }
    | { op: 'row'; path: string; flags: Flags }
    | { op: 'search'; q: string; flags: Flags }
    | { op: 'count' };

function run(call: Call): unknown {
    switch (call.op) {
        case 'nav':
            return sectionNavItems(ACCOUNTS_SECTIONS, call.flags as SharedData).map(({ title, url }) => ({ title, url }));
        case 'tabs':
            return Object.fromEntries(
                ACCOUNTS_SECTIONS.map((section) => [section.key, visibleTabs(section, call.flags as SharedData).map((tab) => tab.url)]),
            );
        case 'row': {
            const row = sectionTabRow(call.path, call.flags as SharedData);
            return row ? { section: row.section.key, current: row.current.url, tabs: row.tabs.length } : null;
        }
        case 'search': {
            const flags = call.flags as SharedData;
            const groups: NavGroup[] = [
                { label: 'Accounts', items: sectionNavItems(ACCOUNTS_SECTIONS, flags) },
                ...sectionSearchGroups([['Accounts', ACCOUNTS_SECTIONS]], flags),
            ];
            return matchMenus(groups, call.q).map((hit) => hit.url);
        }
        case 'count':
            return { sections: ACCOUNTS_SECTIONS.length, tabs: ACCOUNTS_SECTIONS.reduce((carry, section) => carry + section.tabs.length, 0) };
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
