/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10): product families.
 *
 * The SKU proposed for a new size: the family SKU, a dash, and the size with
 * only letters, digits and a decimal point, upper case (MINIKET + "25 kg" =
 * MINIKET-25KG). The server's ProductFamily::proposeSizeSku is the same rule.
 * A proposal only: the popup lets it be typed over.
 */
export function proposeSizeSku(familySku: string, value: string): string {
    const size = value.replace(/[^A-Za-z0-9.]/g, '').toUpperCase();
    const base = familySku.trim().toUpperCase();

    return size === '' ? base : `${base}-${size}`;
}

/** "Miniket Rice - 25 kg", the words every picker shows for a size. */
export function sizeLabel(familyName: string, value: string | null | undefined): string {
    const size = (value ?? '').trim();

    return size === '' ? familyName : `${familyName} - ${size}`;
}
