/**
 * The section registry, the tab row and the header search's menu matching,
 * run for real (round 3, 16 Oct 2026). Read by SectionNavigationTest.
 *
 * Input (stdin): a JSON list of calls. Output (stdout): a JSON list of results,
 * one per call, in the same order.
 */
import { INVENTORY_SECTIONS } from '@/components/inventory/inventory-sections';
import { type NavGroup } from '@/components/nav-groups';
import { SALES_SECTIONS } from '@/components/sales/sales-sections';
import { matchMenus } from '@/lib/menu-search';
import {
    ALL_SECTIONS,
    findSectionTab,
    sectionNavItems,
    sectionSearchGroups,
    sectionTabRow,
    type ModuleSection,
    type Translate,
} from '@/lib/sections';
import { type SharedData } from '@/types';
import { Circle } from 'lucide-react';
import { readFileSync } from 'node:fs';

type Flags = Record<string, unknown>;

const has = (name: string) => (f: SharedData) => !!f[name];

/**
 * A small registry with the shapes the real one has: a module overview whose
 * prefix is shorter than a gated screen inside it, one address shared by two
 * modules (the earlier one listed first), and a document page that only its
 * page can place on a tab.
 */
const SYNTHETIC: ModuleSection[] = [
    {
        key: 'inv-overview',
        title: 'Overview',
        icon: Circle,
        tabs: [
            { title: 'Overview', url: '/inv', can: has('inv') },
            { title: 'Valuation', url: '/inv/valuation', can: has('inv') },
        ],
    },
    {
        key: 'inv-requests',
        title: 'Requests',
        icon: Circle,
        tabs: [
            { title: 'Requests', url: '/inv/requests', can: has('approve') },
            { title: 'History', url: '/inv/requests/history', can: has('approve') },
        ],
    },
    {
        key: 'sales-money',
        title: 'Money',
        icon: Circle,
        badge: (p) => (p.salesBadge as number | undefined) || undefined,
        tabs: [
            { title: 'Invoices', url: '/sales/invoices', can: has('sales') },
            { title: 'Ageing', url: '/books/ageing', can: has('sales'), keywords: 'debtors who owes us' },
        ],
    },
    {
        key: 'books-reports',
        title: 'Reports',
        titleKey: 'menu.report_cash_position',
        icon: Circle,
        tabs: [
            { title: 'Ageing', url: '/books/ageing', can: has('books') },
            { title: 'Trial balance', url: '/books/trial-balance', can: has('books'), keywords: 'tb' },
            { title: 'Valuation', url: '/inv/valuation', can: has('books') },
        ],
    },
    {
        key: 'books-vouchers',
        title: 'Vouchers',
        icon: Circle,
        tabs: [
            { title: 'Payment', url: '/books/vouchers/payment', also: ['/books/vouchers/doc'], can: has('books') },
            { title: 'Receipt', url: '/books/vouchers/receipt', can: has('books') },
            { title: 'My leave', titleKey: 'tabs.my_leave', url: '/books/leave', can: has('books') },
        ],
    },
];

const REGISTRIES: Record<string, ModuleSection[]> = {
    synthetic: SYNTHETIC,
    all: ALL_SECTIONS,
};

/** A stand-in translator: marks every key it was asked for. */
const fakeT: Translate = (key) => `«${key}»`;

type Call =
    | { op: 'find'; path: string; flags?: Flags | null; registry: string }
    | { op: 'row'; path: string; flags: Flags; registry: string }
    | { op: 'nav'; flags: Flags; registry: string; translate?: boolean }
    | { op: 'search'; q: string; flags: Flags; registry: string; translate?: boolean }
    | { op: 'keys'; registry: string };

function run(call: Call): unknown {
    const sections = REGISTRIES[call.registry];

    switch (call.op) {
        case 'find': {
            const found = findSectionTab(call.path, (call.flags ?? undefined) as SharedData | undefined, sections);
            return found ? { section: found.section.key, tab: found.tab.url } : null;
        }
        case 'row': {
            const row = sectionTabRow(call.path, call.flags as SharedData, sections);
            return row ? { section: row.section.key, tabs: row.tabs.map((tab) => tab.url), current: row.current.url } : null;
        }
        case 'nav':
            return sectionNavItems(sections, call.flags as SharedData, call.translate ? fakeT : undefined).map(({ title, url, badge }) => ({
                title,
                url,
                badge: badge ?? null,
            }));
        case 'search': {
            const t = call.translate ? fakeT : undefined;
            const flags = call.flags as SharedData;
            // The shape useNavGroups builds: drawn section rows first, the
            // never-drawn tab groups after them.
            const modules: [string, ModuleSection[]][] =
                call.registry === 'all'
                    ? [
                          ['Inventory', INVENTORY_SECTIONS],
                          ['Sales', SALES_SECTIONS],
                      ]
                    : [['Module', sections]];
            const groups: NavGroup[] = [
                ...modules.map(([label, list]) => ({ label, items: sectionNavItems(list, flags, t) })),
                ...sectionSearchGroups(modules, flags, t),
            ];
            return matchMenus(groups, call.q);
        }
        case 'keys':
            return sections.map((section) => section.key);
    }
}

const calls = JSON.parse(readFileSync(0, 'utf8')) as Call[];
process.stdout.write(JSON.stringify(calls.map(run)));
