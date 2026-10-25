import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { RecordHistory } from '@/components/record-history';
import { EngineActions, type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { GiveSampleDialog, MakeSampleDialog, type SampleOptions } from '@/components/sales/sample-dialogs';
import { SampleRows, type SampleRow } from '@/components/sales/samples-received';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { dmy } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Row = SampleRow & { actions: EngineAction[] };

type Make = {
    id: number;
    number: string;
    made_on: string;
    product: string | null;
    warehouse: string | null;
    quantity: string;
    left: string;
    batch: string | null;
    cost: string | null;
    by: string | null;
};

type Filters = {
    from: string;
    to: string;
    purpose: string;
    recipient_type: string;
    recipient_id: string;
    search: string;
    product_id: string;
};

/**
 * ⭐ Samples (Joy, 25 Oct 2026): Sales > Sell > Samples. Every sample given
 * free (tasting, gift, marketing), with what it cost and the delivery we
 * paid; Reverse (the corrections engine) and History on each. Give sample and
 * Make samples open in a popup, date first.
 */
export default function SamplesIndex({
    filters: initialFilters,
    samples,
    totals,
    purposes,
    methods,
    canManage,
    seesCost,
    options,
    products,
    makes,
    give,
    reversalReasons,
    reportUrl,
    today,
}: {
    filters: Filters;
    samples: Paginated<Row>;
    totals: { count: number; cost: string | null; delivery: string };
    purposes: Record<string, string>;
    methods: Record<string, string>;
    canManage: boolean;
    seesCost: boolean;
    options: SampleOptions | null;
    products: { id: number; name: string }[];
    makes: Make[];
    give: number | null;
    reversalReasons: ReasonOption[];
    reportUrl: string;
    today: string;
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/sales/samples', initialFilters);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: t('smp.title'), href: '/sales/samples' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('smp.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{t('smp.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('smp.intro')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canManage && options && (
                            <>
                                <GiveSampleDialog options={options} purposes={purposes} methods={methods} today={today} presetProduct={give} />
                                <MakeSampleDialog options={options} today={today} />
                            </>
                        )}
                        {seesCost && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={reportUrl}>{t('smp.report')}</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Stat label={t('smp.given_count')} value={String(totals.count)} />
                    {totals.cost !== null && <Stat label={t('smp.cost')} value={formatMoney(totals.cost)} />}
                    <Stat label={t('smp.delivery_paid')} value={formatMoney(totals.delivery)} />
                </div>

                <FilterBar onClear={reset} showClear={hasActiveFilters}>
                    <FilterField label={t('smp.from')} htmlFor="samples-from">
                        <Input
                            id="samples-from"
                            type="date"
                            className="w-44"
                            value={filters.from}
                            onChange={(e) => setFilter('from', e.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('smp.to_date')} htmlFor="samples-to">
                        <Input id="samples-to" type="date" className="w-44" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                    </FilterField>
                    <FilterField label={t('smp.purpose')} htmlFor="samples-purpose">
                        <SearchSelect id="samples-purpose" value={filters.purpose} onChange={(e) => setFilter('purpose', e.target.value)}>
                            <option value="">{t('smp.all_purposes')}</option>
                            {Object.keys(purposes).map((key) => (
                                <option key={key} value={key}>
                                    {t(`smp.purpose_${key}` as 'smp.purpose_tasting')}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                    <FilterField label={t('smp.recipient')} htmlFor="samples-search">
                        <Input
                            id="samples-search"
                            className="w-52"
                            placeholder={t('smp.search_hint')}
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                        />
                    </FilterField>
                    <FilterField label={t('smp.product')} htmlFor="samples-product" className="min-w-56">
                        <SearchSelect id="samples-product" value={filters.product_id} onChange={(e) => setFilter('product_id', e.target.value)}>
                            <option value="">{t('smp.all_products')}</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <SampleRows
                    rows={samples.data}
                    showCost={seesCost}
                    actions={(row) => (
                        <div className="flex flex-col items-end gap-1">
                            <EngineActions
                                type="sales.sample_issue"
                                id={row.id}
                                recordLabel={row.number}
                                actions={(row as Row).actions}
                                reasons={reversalReasons}
                                labels={{ reverse: t('smp.reverse') }}
                            />
                            <RecordHistory type="sales.sample_issue" id={row.id} label={row.number} />
                        </div>
                    )}
                />
                <Pagination meta={samples} />

                {makes.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">{t('smp.makes')}</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">{t('smp.date')}</th>
                                        <th className="p-3">{t('smp.number')}</th>
                                        <th className="p-3">{t('smp.product')}</th>
                                        <th className="p-3">{t('smp.batch')}</th>
                                        <th className="p-3 text-right">{t('smp.made')}</th>
                                        <th className="p-3 text-right">{t('smp.left')}</th>
                                        {seesCost && <th className="p-3 text-right">{t('smp.cost')}</th>}
                                        <th className="p-3">{t('smp.given_by')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {makes.map((make) => (
                                        <tr key={make.id} className="border-t">
                                            <td className="p-3 whitespace-nowrap">{dmy(make.made_on)}</td>
                                            <td className="p-3 font-mono">{make.number}</td>
                                            <td className="p-3">
                                                {make.product}
                                                <div className="text-muted-foreground text-xs">{make.warehouse}</div>
                                            </td>
                                            <td className="p-3 font-mono text-xs">{make.batch}</td>
                                            <td className="p-3 text-right tabular-nums">{make.quantity}</td>
                                            <td className="p-3 text-right tabular-nums">{make.left}</td>
                                            {seesCost && <td className="p-3 text-right tabular-nums">{formatMoney(make.cost)}</td>}
                                            <td className="p-3">{make.by}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card w-fit rounded-xl border px-4 py-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="text-lg font-semibold tabular-nums">{value}</p>
        </div>
    );
}
