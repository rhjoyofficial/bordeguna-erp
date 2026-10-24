import { ConfirmDialog } from '@/components/confirm-dialog';
import { JoinFamilyButton, type JoinableFamily, MakeFamilyButton } from '@/components/inventory/product-family-dialogs';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';

export interface ProductFamilyInfo {
    id: number;
    name: string;
    attribute_name: string;
    value: string | null;
    sizes: { id: number; name: string; value: string | null }[];
}

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10): the product page's family line.
 * A size says which family it belongs to, links its other sizes and offers
 * Leave family; a single product offers Make it a family and Join a family.
 * Each changes only which family the product belongs to.
 */
export function ProductFamilyStrip({
    product,
    family,
    families,
    canManage,
}: {
    product: { id: number; name: string; sku: string | null };
    family: ProductFamilyInfo | null;
    families: JoinableFamily[];
    canManage: boolean;
}) {
    const { t } = useTranslation();

    if (family === null && !canManage) {
        return null;
    }

    const others = family?.sizes.filter((size) => size.id !== product.id) ?? [];

    return (
        <section className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2 text-sm">
            {family ? (
                <>
                    <span>
                        {t('ipf.size_of', { attribute: family.attribute_name, value: family.value ?? '' })}{' '}
                        <Link href={`/inventory/product-families/${family.id}`} className="font-medium underline underline-offset-2">
                            {family.name}
                        </Link>
                    </span>
                    {others.length > 0 && (
                        <span className="text-muted-foreground inline-flex flex-wrap items-center gap-1.5">
                            {t('ipf.other_sizes')}
                            {others.map((size) => (
                                <Link
                                    key={size.id}
                                    href={`/inventory/products/${size.id}`}
                                    className="bg-muted rounded px-1.5 py-0.5 text-xs hover:underline"
                                >
                                    {size.value ?? size.name}
                                </Link>
                            ))}
                        </span>
                    )}
                    {canManage && (
                        <span className="ml-auto">
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline">
                                        {t('ipf.leave')}
                                    </Button>
                                }
                                title={t('ipf.leave_title', { name: product.name, family: family.name })}
                                description={t('ipf.leave_help')}
                                confirmLabel={t('ipf.leave')}
                                onConfirm={() => router.post(route('inventory.products.leave-family', product.id), {}, { preserveScroll: true })}
                            />
                        </span>
                    )}
                </>
            ) : (
                <>
                    <span className="text-muted-foreground">{t('ipf.single')}</span>
                    <span className="ml-auto flex flex-wrap gap-2">
                        <MakeFamilyButton product={product} />
                        {families.length > 0 && <JoinFamilyButton product={product} families={families} />}
                    </span>
                </>
            )}
        </section>
    );
}
