import { Pagination } from '@/components/data-table/pagination';
import { CompactControls } from '@/components/form/compact-controls';
import { CompactTable, Hint, inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';

interface FamilyRow {
    id: number;
    name: string;
    sku: string;
    attribute_name: string;
    category: string | null;
    brand: string | null;
    sizes: number;
    on_hand: string;
    stock_value: string;
}

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10): every product family, with its
 * number of sizes and the family's stock (per unit) and value. A family is
 * made from a product's page (Make it a family); this list only reads.
 */
export default function ProductFamiliesIndex({ families, filters: initialFilters }: { families: Paginated<FamilyRow>; filters: { search: string } }) {
    const { t } = useTranslation();
    const { filters, setFilter } = useFilters('/inventory/product-families', initialFilters);

    return (
        <AppLayout breadcrumbs={inventoryCrumbs('Catalogue', 'Families')}>
            <Head title={t('ipf.title')} />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar note={t('ipf.list_note')} />

                <CompactControls>
                    <label className="border-input bg-background flex h-9 w-72 max-w-full items-center gap-2 rounded-md border px-2.5">
                        <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                        <Input
                            aria-label={t('ipf.search')}
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder={t('ipf.search')}
                            className="h-7 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                        />
                    </label>
                </CompactControls>

                <div className="bg-card text-card-foreground rounded-xl border">
                    <CompactTable minWidth="44rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>{t('ipf.family_sku')}</th>
                                <th>{t('ipf.family')}</th>
                                <th>{t('ipf.attribute')}</th>
                                <th>{t('ipf.brand')}</th>
                                <th>{t('ipf.category')}</th>
                                <th className="text-right">{t('ipf.sizes')}</th>
                                <th className="text-right">
                                    <span className="inline-flex items-center gap-1">
                                        {t('ipf.on_hand')}
                                        <Hint text={t('ipf.on_hand_hint')} />
                                    </span>
                                </th>
                                <th className="text-right">{t('ipf.stock_value')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {families.data.map((family) => (
                                <tr key={family.id}>
                                    <td className="font-mono text-xs">{family.sku}</td>
                                    <td>
                                        <Link
                                            href={`/inventory/product-families/${family.id}`}
                                            className="font-medium underline-offset-2 hover:underline"
                                        >
                                            {family.name}
                                        </Link>
                                    </td>
                                    <td className="text-muted-foreground">{family.attribute_name}</td>
                                    <td className="text-muted-foreground">{family.brand ?? '-'}</td>
                                    <td className="text-muted-foreground">{family.category ?? '-'}</td>
                                    <td className="text-right tabular-nums">{family.sizes}</td>
                                    <td className="text-right tabular-nums">{family.on_hand}</td>
                                    <td className="text-right tabular-nums">{formatMoney(family.stock_value)}</td>
                                </tr>
                            ))}
                            {families.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground py-6 text-center">
                                        {t('ipf.empty')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                </div>
                <Pagination meta={families} />
            </div>
        </AppLayout>
    );
}
