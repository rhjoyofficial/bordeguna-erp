/**
 * The business's own supplier types (Purchases > Suppliers > Supplier types,
 * 17 Oct 2026), as every page sends them: each type with its state.
 *
 * ⚠ A picker offers the active types plus whatever the record already holds,
 * so a supplier on a switched-off type still shows it and saves unchanged.
 * The server applies the same rule (SupplierTypes::rule).
 */
export interface SupplierTypeOption {
    key: string;
    name: string;
    active: boolean;
}

export function pickableSupplierTypes(types: SupplierTypeOption[], current: string | null | undefined): SupplierTypeOption[] {
    return types.filter((type) => type.active || type.key === current);
}

export function supplierTypeLabel(type: SupplierTypeOption): string {
    return type.active ? type.name : `${type.name} (switched off)`;
}

export function supplierTypeName(types: SupplierTypeOption[], key: string): string {
    return types.find((type) => type.key === key)?.name ?? key;
}
