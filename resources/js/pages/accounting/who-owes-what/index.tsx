import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type Side = 'us' | 'them' | 'even';
type Kind = 'customer' | 'supplier' | 'both' | 'courier';

interface Row {
    key: string;
    kind: Kind;
    name: string;
    phone: string | null;
    customer_id: number | null;
    supplier_id: number | null;
    they_owe: string | null;
    our_advance: string | null;
    we_owe: string | null;
    their_advance: string | null;
    net: string;
    net_side: Side;
}

interface Props {
    filters: { side: string; kind: string; q: string };
    totals: { they_owe: string; we_owe: string; net: string; net_side: Side; owe_us_count: number; we_owe_count: number };
    rows: Row[];
    shown: number;
    total: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Who owes what', href: '/accounting/who-owes-what' },
];

/**
 * ⭐ Who owes what (Joy, 26 Oct 2026: "from two place i needs to see how much
 * ayesha ows ... for all i needs in one place"). Every customer, supplier and
 * courier with a balance, one line each; a firm that is both is one line with
 * both sides and one net. Each name opens its own page; a firm that is both
 * also opens its combined statement.
 */
export default function WhoOwesWhatIndex({ filters, totals, rows, shown, total }: Props) {
    const { t } = useTranslation();
    const [form, setForm] = useState(filters);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.who-owes-what'), form, { preserveState: true });
    };

    const netText = (side: Side, amount: string) =>
        side === 'us' ? t('wow.net_us', { amount }) : side === 'them' ? t('wow.net_them', { amount }) : t('wow.net_even');

    const kindLabel = (kind: Kind) =>
        ({ customer: t('wow.kind_customer'), supplier: t('wow.kind_supplier'), both: t('wow.kind_both'), courier: t('wow.kind_courier') })[kind];

    const nameHref = (row: Row) =>
        row.customer_id !== null
            ? `/sales/customers/${row.customer_id}`
            : row.supplier_id !== null
              ? `/purchasing/suppliers/${row.supplier_id}`
              : '/sales/courier-statements';

    const query = new URLSearchParams(filters).toString();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('wow.title')} />
            <ReportPage>
                <ReportHeading
                    title={t('wow.title')}
                    description={t('wow.intro')}
                    actions={
                        <Button size="sm" variant="outline" asChild>
                            {/* A plain link: the CSV is a file download, not an Inertia visit. */}
                            <a href={`/accounting/who-owes-what/export?${query}`}>{t('wow.download')}</a>
                        </Button>
                    }
                />

                <StatRow columns={3}>
                    <StatTile
                        label={t('wow.total_owe_us')}
                        value={totals.they_owe}
                        hint={t('wow.parties', { count: totals.owe_us_count })}
                        tone="good"
                    />
                    <StatTile
                        label={t('wow.total_we_owe')}
                        value={totals.we_owe}
                        hint={t('wow.parties', { count: totals.we_owe_count })}
                        tone="bad"
                    />
                    <StatTile label={t('wow.total_net')} value={netText(totals.net_side, totals.net)} />
                </StatRow>

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="wow-side">{t('wow.side')}</Label>
                        <SearchSelect fit id="wow-side" value={form.side} onChange={(e) => setForm({ ...form, side: e.target.value })}>
                            <option value="all">{t('wow.side_all')}</option>
                            <option value="owe_us">{t('wow.side_owe_us')}</option>
                            <option value="we_owe">{t('wow.side_we_owe')}</option>
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wow-kind">{t('wow.kind')}</Label>
                        <SearchSelect fit id="wow-kind" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                            <option value="all">{t('wow.kind_all')}</option>
                            <option value="customer">{t('wow.kind_customer')}</option>
                            <option value="supplier">{t('wow.kind_supplier')}</option>
                            <option value="both">{t('wow.kind_both')}</option>
                            <option value="courier">{t('wow.kind_courier')}</option>
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wow-q">{t('wow.search')}</Label>
                        <Input id="wow-q" className="w-56" value={form.q} onChange={(e) => setForm({ ...form, q: e.target.value })} />
                    </div>
                </FilterBar>

                <p className="text-muted-foreground text-xs">{t('wow.shown', { shown, total })}</p>

                <ReportTable
                    minWidth="64rem"
                    head={
                        <>
                            <th className="py-2">{t('wow.col_name')}</th>
                            <th className="py-2">{t('wow.kind')}</th>
                            <th className="py-2 text-right">{t('wow.col_they_owe')}</th>
                            <th className="py-2 text-right">{t('wow.col_our_advance')}</th>
                            <th className="py-2 text-right">{t('wow.col_we_owe')}</th>
                            <th className="py-2 text-right">{t('wow.col_their_advance')}</th>
                            <th className="py-2 text-right">{t('wow.col_net')}</th>
                        </>
                    }
                >
                    {rows.length === 0 && <EmptyRow colSpan={7}>{t('wow.empty')}</EmptyRow>}
                    {rows.map((row) => (
                        <tr key={row.key} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">
                                <Link href={nameHref(row)} className="font-medium hover:underline">
                                    {row.name}
                                </Link>
                                {row.phone && <div className="text-muted-foreground text-xs">{row.phone}</div>}
                                {row.kind === 'both' && row.customer_id !== null && (
                                    <Link href={`/accounting/party-statement?customer=${row.customer_id}`} className="text-xs underline">
                                        {t('wow.statement')}
                                    </Link>
                                )}
                            </td>
                            <td className="text-muted-foreground py-2 text-xs">{kindLabel(row.kind)}</td>
                            <td className="py-2 text-right tabular-nums">{row.they_owe ?? '-'}</td>
                            <td className="py-2 text-right tabular-nums">{row.our_advance ?? '-'}</td>
                            <td className="py-2 text-right tabular-nums">{row.we_owe ?? '-'}</td>
                            <td className="py-2 text-right tabular-nums">{row.their_advance ?? '-'}</td>
                            <td
                                className={`py-2 text-right font-semibold tabular-nums ${row.net_side === 'us' ? 'text-emerald-700 dark:text-emerald-400' : row.net_side === 'them' ? 'text-red-600 dark:text-red-400' : ''}`}
                            >
                                {netText(row.net_side, row.net)}
                            </td>
                        </tr>
                    ))}
                </ReportTable>
            </ReportPage>
        </AppLayout>
    );
}
