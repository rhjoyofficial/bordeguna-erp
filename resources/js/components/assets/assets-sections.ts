import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Boxes, KeyRound, Landmark, LayoutDashboard } from 'lucide-react';

/**
 * ⭐ Assets in ONE sidebar group of four sections (Joy, 15 Oct 2026, after
 * HRM). It was ten links; every screen keeps its address and becomes a tab
 * inside its section, drawn by SectionTabs above the page.
 *
 * The gates are the same shared permission flags the ten links used, one for
 * one (verified against app/Modules/Assets/routes/web.php):
 * - Overview (new): any of the three view permissions; the page hides each
 *   card by the one its list needs.
 * - Things: `assets.items.view` for every tab (the buttons that change a
 *   thing still need `assets.items.manage`, on the page).
 * - Accounts & logins: the hub on `assets.accounts.view`, the leaver check on
 *   `assets.accounts.manage`.
 * - Fixed assets: `assets.view` for both tabs; the run stays owner only.
 *
 * ⚠ `/assets` is a prefix of every address here. The registry matches by the
 * LONGEST prefix, so the Fixed assets register wins only when nothing longer
 * does: /assets/items lights Things, /assets lights Fixed assets.
 */

type Flags = SharedData;

const items = (f: Flags) => f.canViewAssetItems;
const any = (f: Flags) => f.canViewAssetItems || f.canViewAccounts || f.canViewAssets;

export const ASSETS_SECTIONS: ModuleSection[] = [
    {
        key: 'assets-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [{ title: 'Overview', url: '/assets/overview', can: any }],
    },
    {
        key: 'assets-things',
        title: 'Things',
        icon: Boxes,
        tabs: [
            { title: 'Register', url: '/assets/items', can: items },
            { title: 'Hand-overs', url: '/assets/movements', can: items },
            { title: 'Stock-takes', url: '/assets/counts', can: items },
            { title: 'Maintenance', url: '/assets/maintenance', can: items },
            // Setup is two screens at their old addresses: the simplest honest
            // way to keep both, rather than a merged page (Joy, 15 Oct 2026).
            { title: 'Categories', url: '/assets/categories', can: items },
            { title: 'Outside parties', url: '/assets/parties', can: items },
        ],
    },
    {
        key: 'assets-accounts',
        title: 'Accounts & logins',
        icon: KeyRound,
        tabs: [
            { title: 'Accounts hub', url: '/assets/accounts', can: (f) => f.canViewAccounts },
            { title: 'Leaver check', url: '/assets/accounts/leaver-check', can: (f) => f.canManageAccounts },
        ],
    },
    {
        key: 'assets-fixed',
        title: 'Fixed assets',
        icon: Landmark,
        tabs: [
            { title: 'Register', url: '/assets', can: (f) => f.canViewAssets },
            { title: 'Depreciation', url: '/assets/depreciation', can: (f) => f.canViewAssets },
        ],
    },
];
