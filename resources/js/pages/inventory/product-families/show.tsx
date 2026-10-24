import { CompactTable, Hint, inventoryCrumbs, Panel, Pill, Stat } from '@/components/inventory/inventory-ui';
import { AddSizeButton, EditFamilyButton } from '@/components/inventory/product-family-dialogs';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Family {
    id: number;
    name: string;
    sku: string;
    attribute_name: string;
    description: string | null;
    category_id: number | null;
    category: string | null;
    brand_id: number | null;
    brand: string | null;
    kind: 'products' | 'raw';
}

interface Size {
    id: number;
    name: string;
    sku: string;
    value: string | null;
    label: string;
    unit: string | null;
    is_active: boolean;
    stocked: boolean;
    on_hand: string;
    stock_value: string;
    mrp: string | null;
    /** {price_group_id: "1,250.00"}; a group with no price is absent. */
    prices: Record<string, string | null>;
}

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): one family and every
 * size in it, each its own product: its stock, value, MRP and today's price
 * in each price group, with a link to the product. Add a size and Edit work
 * in popups; Delete (to the Recycle bin, Q5) stays greyed with its reason
 * while the family still has sizes.
 */
export default function ProductFamilyShow({
    family,
    sizes,
    totals,
    price_groups,
    template,
    units,
    categories,
    brands,
    reasons,
    can,
}: {
    family: Family;
    sizes: Size[];
    totals: { sizes: number; on_hand: string; value: string };
    price_groups: { id: number; code: string; name: string }[];
    template: { name: string; unit: string | null; type: string | null } | null;
    units: { id: number; name: string; abbreviation: string }[];
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    reasons: ReasonOption[];
    can: { manage: boolean; set_prices: boolean; delete: boolean; delete_reason: string | null };
}) {
    const { t } = useTranslation();
    const base = `/inventory/product-families/${family.id}`;

    return (
        <AppLayout breadcrumbs={inventoryCrumbs('Catalogue', 'Families', { title: family.name, href: base })}>
            <Head title={family.name} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{family.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            <span className="font-mono text-xs">{family.sku}</span>
                            {` · ${t('ipf.attribute')}: ${family.attribute_name}`}
                            {family.category && ` · ${family.category}`}
                            {family.brand && ` · ${family.brand}`}
                        </p>
                        {family.description && <p className="mt-1 max-w-2xl text-sm">{family.description}</p>}
                    </div>
                    {can.manage && (
                        <div className="flex flex-wrap items-start gap-2">
                            <AddSizeButton family={family} template={template} units={units} />
                            <EditFamilyButton family={family} categories={categories} brands={brands} />
                            {can.delete ? (
                                <CorrectionReasonDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            {t('ipf.delete')}
                                        </Button>
                                    }
                                    title={t('ipf.delete_title', { name: family.name })}
                                    description={t('ipf.delete_help')}
                                    reasons={reasons}
                                    submitUrl={`${base}/delete`}
                                    confirmLabel={t('ipf.delete')}
                                    destructive
                                />
                            ) : (
                                // A blocked button stays visible, greyed, with its reason.
                                <span className="flex max-w-60 flex-col items-start gap-0.5">
                                    <Button size="sm" variant="outline" disabled title={can.delete_reason ?? undefined}>
                                        {t('ipf.delete')}
                                    </Button>
                                    <span className="text-muted-foreground text-[11px] leading-tight">{can.delete_reason}</span>
                                </span>
                            )}
                        </div>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat label={t('ipf.sizes')} value={totals.sizes} />
                    <Stat label={t('ipf.on_hand')} value={totals.on_hand} hint={t('ipf.on_hand_hint')} />
                    <Stat label={t('ipf.stock_value')} value={`Tk ${formatMoney(totals.value)}`} />
                </div>

                <Panel
                    title={t('ipf.sizes')}
                    flush
                    aside={
                        can.set_prices && sizes.length > 0 ? (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={`/sales/prices?family=${family.id}`}>{t('ipf.set_prices')}</Link>
                            </Button>
                        ) : undefined
                    }
                >
                    <CompactTable minWidth="48rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>{family.attribute_name}</th>
                                <th>{t('ipf.sku')}</th>
                                <th>{t('ipf.product')}</th>
                                <th className="text-right">{t('ipf.on_hand')}</th>
                                <th className="text-right">{t('ipf.stock_value')}</th>
                                <th className="text-right">{t('ipf.mrp')}</th>
                                {price_groups.map((group) => (
                                    <th key={group.id} className="text-right">
                                        {group.name}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {sizes.map((size) => (
                                <tr key={size.id} className={size.is_active ? undefined : 'text-muted-foreground'}>
                                    <td className="font-medium">{size.value}</td>
                                    <td className="font-mono text-xs">{size.sku}</td>
                                    <td>
                                        <span className="inline-flex flex-wrap items-center gap-1.5">
                                            <Link href={`/inventory/products/${size.id}`} className="underline-offset-2 hover:underline">
                                                {size.name}
                                            </Link>
                                            {!size.is_active && <Pill tone="muted">{t('ipf.inactive')}</Pill>}
                                        </span>
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {size.stocked ? `${formatQuantity(size.on_hand)} ${size.unit ?? ''}` : '-'}
                                    </td>
                                    <td className="text-right tabular-nums">{formatMoney(size.stock_value)}</td>
                                    <td className="text-right tabular-nums">{size.mrp ? formatMoney(size.mrp) : '-'}</td>
                                    {price_groups.map((group) => (
                                        <td key={group.id} className="text-right tabular-nums">
                                            {size.prices[group.id] ? formatMoney(size.prices[group.id]) : '-'}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                            {sizes.length === 0 && (
                                <tr>
                                    <td colSpan={6 + price_groups.length} className="text-muted-foreground py-6 text-center">
                                        {t('ipf.no_sizes')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                    <p className="text-muted-foreground flex items-center gap-1 px-4 py-2 text-xs">
                        {t('ipf.prices_title')}
                        <Hint text={t('ipf.prices_hint')} />
                    </p>
                </Panel>
            </div>
        </AppLayout>
    );
}
