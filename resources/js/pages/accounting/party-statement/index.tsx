import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Amount, DateCell, LedgerHead } from '@/components/ledger-text';
import { EmptyRow, FilterBar, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

type Signed = { amount: string; side: 'us' | 'them' | 'even' };

interface Line {
    date: string;
    side: 'customer' | 'supplier';
    kind: string;
    label: string;
    reference: string | null;
    they_owe_change: string | null;
    we_owe_change: string | null;
    net: Signed;
}

interface Summary {
    opening: Signed;
    closing: Signed;
    they_owe_opening: string;
    they_owe_closing: string;
    we_owe_opening: string;
    we_owe_closing: string;
    consistent: boolean | null;
}

interface Props {
    from: string;
    to: string;
    parties: { supplier_id: number; label: string }[];
    party: { supplier_id: number; supplier: string; customer_id: number; customer: string } | null;
    lines: Line[];
    summary: Summary | null;
    issuer: Issuer;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Party statement', href: '/accounting/party-statement' },
];

/**
 * ⭐ The combined party statement (decision D-F, 18 Oct 2026): one firm that is
 * our supplier and our customer, both accounts on one page, a running net for
 * the range, printable for the party. Each line keeps the account it belongs
 * to; a set-off shows on both sides and leaves the net where it was.
 */
export default function PartyStatementIndex({ from, to, parties, party, lines, summary, issuer }: Props) {
    const { t } = useTranslation();
    const [filters, setFilters] = useState({ supplier: party ? String(party.supplier_id) : '', from, to });

    const netText = (net: Signed) =>
        net.side === 'us'
            ? t('party.net_us', { amount: net.amount })
            : net.side === 'them'
              ? t('party.net_them', { amount: net.amount })
              : t('party.net_even');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.party-statement'), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('party_statement.title')} />
            <ReportPage>
                <div className="print:hidden">
                    <ReportHeading
                        title={t('party_statement.title')}
                        description={t('party_statement.intro')}
                        actions={
                            party !== null && (
                                <Button size="sm" variant="outline" onClick={() => window.print()}>
                                    {t('set_off.print')}
                                </Button>
                            )
                        }
                    />

                    <FilterBar onSubmit={submit}>
                        <div className="grid gap-2">
                            <Label htmlFor="supplier">{t('set_off.party')}</Label>
                            <SearchSelect
                                fit
                                id="supplier"
                                value={filters.supplier}
                                onChange={(e) => setFilters({ ...filters, supplier: e.target.value })}
                            >
                                <option value="">{t('party_statement.choose')}</option>
                                {parties.map((option) => (
                                    <option key={option.supplier_id} value={option.supplier_id}>
                                        {option.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="from">{t('party_statement.from')}</Label>
                            <Input id="from" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to">{t('party_statement.to')}</Label>
                            <Input id="to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                        </div>
                    </FilterBar>
                </div>

                {party === null && <p className="text-muted-foreground py-6 text-center text-sm">{t('party_statement.choose_hint')}</p>}

                {party !== null && summary !== null && (
                    <>
                        <div className="hidden print:block">
                            <DocumentLetterhead
                                issuer={issuer}
                                title={t('party_statement.title')}
                                subtitle={`${party.supplier} · ${showDate(from)} - ${showDate(to)}`}
                            />
                        </div>

                        {summary.consistent === false && <Verdict tone="bad">{t('party_statement.not_consistent')}</Verdict>}

                        <StatRow>
                            <StatTile label={t('party_statement.opening_net')} value={netText(summary.opening)} hint={from} />
                            <StatTile label={t('party_statement.they_owe_closing')} value={summary.they_owe_closing} hint={party.customer} />
                            <StatTile label={t('party_statement.we_owe_closing')} value={summary.we_owe_closing} hint={party.supplier} />
                            <StatTile label={t('party_statement.closing_net')} value={netText(summary.closing)} hint={to} />
                        </StatRow>

                        <ReportTable
                            minWidth="60rem"
                            head={
                                <>
                                    <th className="py-2">{t('set_off.date')}</th>
                                    <th className="py-2">{t('party_statement.side')}</th>
                                    <th className="py-2">{t('party_statement.detail')}</th>
                                    <th className="py-2">{t('party_statement.reference')}</th>
                                    <th className="py-2 text-right">{t('party_statement.they_owe_change')}</th>
                                    <th className="py-2 text-right">{t('party_statement.we_owe_change')}</th>
                                    <th className="py-2 text-right">{t('party_statement.net')}</th>
                                </>
                            }
                            foot={
                                <tr className="font-bold">
                                    <td className="py-2" colSpan={6}>
                                        {t('party_statement.closing_net')}
                                    </td>
                                    <td className="py-2 text-right">{netText(summary.closing)}</td>
                                </tr>
                            }
                        >
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <DateCell>{from}</DateCell>
                                </td>
                                <td className="py-2 font-semibold" colSpan={5}>
                                    {t('party_statement.opening_net')}
                                </td>
                                <td className="py-2 text-right">{netText(summary.opening)}</td>
                            </tr>
                            {lines.map((line, index) => (
                                <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        <DateCell>{showDate(line.date)}</DateCell>
                                    </td>
                                    <td className="text-muted-foreground py-2 text-xs">
                                        {line.side === 'customer' ? t('party_statement.side_customer') : t('party_statement.side_supplier')}
                                    </td>
                                    <td className="py-2">
                                        <LedgerHead name={line.label} />
                                    </td>
                                    <td className="py-2 text-xs">{line.reference}</td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.they_owe_change} tone="debit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.we_owe_change} tone="credit" />
                                    </td>
                                    <td className="py-2 text-right text-sm whitespace-nowrap">{netText(line.net)}</td>
                                </tr>
                            ))}
                            {lines.length === 0 && <EmptyRow colSpan={7}>{t('party_statement.empty')}</EmptyRow>}
                        </ReportTable>
                    </>
                )}
            </ReportPage>
        </AppLayout>
    );
}
