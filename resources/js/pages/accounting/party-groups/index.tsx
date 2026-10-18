import { Amount, DocNo } from '@/components/ledger-text';
import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, Verdict } from '@/components/report/report-shell';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Row {
    id: number;
    name: string;
    code: string | null;
    opening: string;
    debits: string;
    credits: string;
    closing: string;
    url: string | null;
}

/**
 * ⭐ Tally's Sundry Creditors and Sundry Debtors (party ledgers, 20 Oct 2026):
 * one row per supplier or customer, opening, debits, credits and closing for
 * the period. A name opens that party's ledger view; a line there opens its
 * document. The total is checked against the control accounts on screen.
 */
export default function PartyGroupIndex({
    group,
    from,
    to,
    accounts,
    rows,
    total,
    ledger_closing,
    unnamed,
    consistent,
    show_all,
}: {
    group: 'creditors' | 'debtors';
    from: string;
    to: string;
    accounts: string[];
    rows: Row[];
    total: { opening: string; debits: string; credits: string; closing: string };
    ledger_closing: string;
    unnamed: string | null;
    consistent: boolean;
    show_all: boolean;
}) {
    const { t } = useTranslation();
    const title = t(group === 'creditors' ? 'pl.sundry_creditors' : 'pl.sundry_debtors');
    const routeName = group === 'creditors' ? 'accounting.sundry-creditors' : 'accounting.sundry-debtors';
    const [filters, setFilters] = useState({ from, to, all: show_all ? '1' : '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title, href: route(routeName) },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route(routeName), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <ReportPage>
                <ReportHeading title={title} description={`${showDate(from)} to ${showDate(to)} · ${accounts.join(' + ')}`} />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                    </div>
                    <label className="flex items-center gap-2 pb-2 text-sm">
                        <input
                            type="checkbox"
                            checked={filters.all === '1'}
                            onChange={(e) => setFilters({ ...filters, all: e.target.checked ? '1' : '' })}
                        />
                        {t('pl.show_all')}
                    </label>
                </FilterBar>

                <p className="text-muted-foreground text-sm">{t(group === 'creditors' ? 'pl.group_creditors_hint' : 'pl.group_debtors_hint')}</p>

                <Verdict tone={consistent ? 'good' : 'bad'}>{consistent ? t('pl.ties_out') : t('pl.not_tied', { amount: unnamed ?? '' })}</Verdict>

                <ReportTable
                    minWidth="44rem"
                    head={
                        <>
                            <th className="py-2">{t('pl.col_party')}</th>
                            <th className="py-2 text-right">{t('pl.col_opening')}</th>
                            <th className="py-2 text-right">{t('pl.col_debit')}</th>
                            <th className="py-2 text-right">{t('pl.col_credit')}</th>
                            <th className="py-2 text-right">{t('pl.col_closing')}</th>
                        </>
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">
                                <DocNo href={row.url}>{row.name}</DocNo>
                                {row.code && <span className="text-muted-foreground ml-2 text-xs">{row.code}</span>}
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.opening} tone="balance" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.debits} tone="debit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.credits} tone="credit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.closing} tone="balance" />
                            </td>
                        </tr>
                    ))}
                    {rows.length === 0 && <EmptyRow colSpan={5}>{t('pl.nobody')}</EmptyRow>}
                    <tr className="font-bold">
                        <td className="py-2">{t('pl.group_total')}</td>
                        <td className="py-2 text-right">
                            <Amount value={total.opening} tone="total" />
                        </td>
                        <td className="py-2 text-right">
                            <Amount value={total.debits} tone="debit" className="font-bold" />
                        </td>
                        <td className="py-2 text-right">
                            <Amount value={total.credits} tone="credit" className="font-bold" />
                        </td>
                        <td className="py-2 text-right">
                            <Amount value={total.closing} tone="total" />
                        </td>
                    </tr>
                </ReportTable>

                <p className="text-muted-foreground text-xs">
                    {accounts.join(' + ')}: {ledger_closing}
                </p>
            </ReportPage>
        </AppLayout>
    );
}
