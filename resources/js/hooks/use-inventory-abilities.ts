import { usePage } from '@inertiajs/react';

/**
 * What the signed-in person may WRITE in Inventory, shared by
 * InventoryServiceProvider as `inventoryAbilities`.
 *
 * ⚠ These hide buttons and menu rows so Staff stop being offered links that
 * answer 403 (21 Sep 2026 audit). They are not the boundary: every route
 * authorizes its own permission.
 *
 * ⚠ Every flag defaults to false. A page rendered without the prop (an error
 * page, a test double) must show less, never more.
 */
export interface InventoryAbilities {
    /** inventory.catalog.manage - products, units, warehouses, bins, categories, brands, purchase units. */
    manageCatalog: boolean;
    /** inventory.stock.adjust - corrections that may post at once, direct transfers, opening stock. */
    adjustStock: boolean;
    /** inventory.stock.request - raise a correction or ask for a move, for a manager to decide. */
    requestStock: boolean;
    /** inventory.stock.approve - stock requests, and the approval limit for corrections. */
    approveStock: boolean;
    /** inventory.production.manage - production runs. */
    manageProduction: boolean;
    /** inventory.harvest.manage - farm harvests and crop failure write-offs. */
    manageHarvest: boolean;
}

const NONE: InventoryAbilities = {
    manageCatalog: false,
    adjustStock: false,
    requestStock: false,
    approveStock: false,
    manageProduction: false,
    manageHarvest: false,
};

export function useInventoryAbilities(): InventoryAbilities {
    const shared = usePage<{ inventoryAbilities?: Partial<InventoryAbilities> }>().props.inventoryAbilities;

    return { ...NONE, ...(shared ?? {}) };
}
