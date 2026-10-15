import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Boxes, Factory, LayoutDashboard, PackageSearch, SlidersHorizontal } from 'lucide-react';

/**
 * ⭐ Inventory in ONE sidebar group of five sections (Joy, 15 Oct 2026, after
 * HRM and Assets). It was 22 links in two groups (Inventory, Inventory
 * setup); every screen keeps its address and becomes a tab inside its
 * section, drawn by SectionTabs above the page.
 *
 * The gates are the same flags the 22 links used, one for one:
 * - the group itself was `canViewInventory` (inventory.reports.view), so
 *   that is the base of every tab;
 * - Requests and Settings: `inventoryAbilities.approveStock`;
 * - Bin placement and Opening stock: `canRecordOpeningStock` (stock.adjust);
 * - Runs: `inventoryAbilities.manageProduction`;
 * - Recipes: `canManageBom`;
 * - Purchase units and Product weights: `inventoryAbilities.manageCatalog`;
 * - Stock value: operating reports AND no accounting.view, as the old link
 *   (Finance & Accounts lists it too, so once only).
 *
 * ⚠ Inventory's write flags arrive as the NESTED shared prop
 * `inventoryAbilities` (InventoryServiceProvider), unlike HRM's flat flags,
 * and every one of them defaults to false when absent.
 *
 * ⚠ New here: `/inventory/raw-materials` (was `/inventory/products?kind=raw`,
 * which the registry could never match: it reads the path alone) and the
 * Yield report as a tab (it was a button on Runs; its route is reports.view).
 *
 * ⚠ `/inventory` is a prefix of every address here. The registry matches by
 * the LONGEST prefix, so Overview wins only when nothing longer does.
 */

type Flags = SharedData;

const view = (f: Flags) => f.canViewInventory;
const approve = (f: Flags) => view(f) && !!f.inventoryAbilities?.approveStock;
const adjust = (f: Flags) => view(f) && f.canRecordOpeningStock;
const production = (f: Flags) => view(f) && !!f.inventoryAbilities?.manageProduction;
const catalog = (f: Flags) => view(f) && !!f.inventoryAbilities?.manageCatalog;
const bom = (f: Flags) => view(f) && f.canManageBom;

export const INVENTORY_SECTIONS: ModuleSection[] = [
    {
        key: 'inventory-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [
            { title: 'Overview', url: '/inventory', can: view },
            { title: 'Low stock', url: '/inventory/low-stocks', can: view },
            { title: 'Expiry', url: '/inventory/expiry', can: view },
            // ⭐ 26 Sep 2026 (Q19): what the shelf is worth, for the Manager. The
            // Finance & Accounts section lists it too, so once only. It lights
            // Inventory while Accounts is not sectioned.
            {
                title: 'Stock value',
                url: '/accounting/inventory-valuation',
                can: (f) => view(f) && f.canViewOperatingReports && !f.canViewAccounting,
            },
        ],
    },
    {
        key: 'inventory-stock',
        title: 'Stock',
        icon: Boxes,
        tabs: [
            { title: 'Current stock', url: '/inventory/stock', can: view },
            { title: 'Transfers', url: '/inventory/stock/transfers', can: view },
            // The approver's queue: the index authorizes stock.approve.
            { title: 'Requests', url: '/inventory/stock-requests', can: approve },
            { title: 'Counts', url: '/inventory/stock-counts', can: view },
            // C-BIN-4/6: placement is floor work, gated like the other floor work.
            { title: 'Bin placement', url: '/inventory/bin-placement', can: adjust },
        ],
    },
    {
        key: 'inventory-production',
        title: 'Production',
        icon: Factory,
        tabs: [
            { title: 'Runs', url: '/inventory/production-runs', can: production },
            { title: 'Yield report', url: '/inventory/production-yield', can: view },
            { title: 'Recipes', url: '/inventory/bills-of-material', can: bom },
        ],
    },
    {
        key: 'inventory-catalogue',
        title: 'Catalogue',
        icon: PackageSearch,
        tabs: [
            // Import, Export, Barcode labels and the Weights sheet stay buttons on
            // the page, gated as before. Labels live under /inventory/labels.
            { title: 'Products', url: '/inventory/products', also: ['/inventory/labels'], can: view },
            { title: 'Raw materials', url: '/inventory/raw-materials', can: view },
        ],
    },
    {
        key: 'inventory-setup',
        title: 'Setup',
        icon: SlidersHorizontal,
        tabs: [
            // ⚠⚠ The cutover's one irreversible document: gated, because the route is.
            { title: 'Opening stock', url: '/inventory/opening-stock', can: adjust },
            { title: 'Warehouses', url: '/inventory/warehouses', can: view },
            { title: 'Bins', url: '/inventory/bins', can: view },
            { title: 'Categories', url: '/inventory/categories', can: view },
            { title: 'Brands', url: '/inventory/brands', can: view },
            { title: 'Units', url: '/inventory/units', can: view },
            { title: 'Purchase units', url: '/inventory/unit-conversions', can: catalog },
            { title: 'Product weights', url: '/inventory/products/weights', can: catalog },
            { title: 'Settings', url: '/inventory/settings', can: approve },
        ],
    },
];
