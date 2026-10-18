/**
 * ⚠ Practice run, 18 Oct 2026: with Brand = Bionic-Garden chosen on a sales
 * order, the product picker still listed LaLaDia products, so a line of the
 * other brand went on the order without a word.
 *
 * The picker offers the chosen brand's products and the products with no brand
 * (shared goods). No brand chosen yet = everything. The product already on the
 * line always stays in its own list, so a line chosen before the brand changed
 * keeps its name and gets a warning (lineBrandMismatch) instead of vanishing.
 */
export interface BrandedProduct {
    id: number;
    brand_id?: number | null;
}

export function productsForBrand<T extends BrandedProduct>(products: T[], brandId: string, keepProductId = ''): T[] {
    if (brandId === '') {
        return products;
    }

    return products.filter(
        (product) =>
            product.brand_id === null ||
            product.brand_id === undefined ||
            String(product.brand_id) === brandId ||
            String(product.id) === keepProductId,
    );
}

/** The other brand's id when the line's product belongs to a brand other than the order's; else null. */
export function lineBrandMismatch(product: BrandedProduct | undefined, brandId: string): number | null {
    if (product === undefined || brandId === '' || product.brand_id === null || product.brand_id === undefined) {
        return null;
    }

    return String(product.brand_id) === brandId ? null : product.brand_id;
}
