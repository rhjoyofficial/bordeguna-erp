import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Amount, DateCell, DocNo, LedgerHead } from '@/components/ledger-text';
import { EmptyRow, FilterBar, ReportPage, ReportTable } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Line {
    date: string;
    journal_number: string;
    journal_id: number;
    memo: string | null;
    source: string | null;
    debit: string | null;
    credit: string | null;
    balance: string;
    balance_side: 'debit' | 'credit';
}

interface HeadLedger {
    account: string;
    slug: string;
    opening: string;
    opening_side: 'debit' | 'credit';
    debits: string;
    credits: string;
    closing: string;
    closing_side: 'debit' | 'credit';
    lines: Line[];
}

/**
 * One counterparty's statement (loans plan v2, phase 1): each loan head its
 * own running ledger with an opening and a closing balance, on the letterhead,
 * printed from the browser like every other document of this ERP.
 */
export default function CounterpartyStatementPage({
    from,
    to,
    counterparty,
    issuer,
    heads,
}: {
    from: string;
    to: string;
    counterparty: { id: number; legal_name: string; address: string | null };
    issuer: Issuer;
    heads: HeadLedger[];
}) {
    const { t } = useTranslation();
    const [dates, setDates] = useState({ from, to });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('counterparties.title'), href: '/accounting/counterparties' },
        { title: counterparty.legal_name, href: route('accounting.counterparties.show', counterparty.id) },
        { title: t('counterparties.statement.title'), href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.counterparties.statement', counterparty.id), dates, { preserveState: true });
    };

    const side = (amount: string, sideOf: 'debit' | 'credit') =>
        amount === '0.00' ? amount : `${amount} ${sideOf === 'credit' ? t('counterparties.cr') : t('counterparties.dr')}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('counterparties.statement.title')}: ${counterparty.legal_name}`} />
            <ReportPage>
                <div className="flex flex-wrap items-end justify-between gap-3 print:hidden">
                    <FilterBar onSubmit={submit} label={t('counterparties.show_button')}>
                        <div className="grid gap-2">
                            <Label htmlFor="from">{t('counterparties.statement.from')}</Label>
                            <Input id="from" type="date" value={dates.from} onChange={(e) => setDates({ ...dates, from: e.target.value })} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to">{t('counterparties.statement.to')}</Label>
                            <Input id="to" type="date" value={dates.to} onChange={(e) => setDates({ ...dates, to: e.target.value })} />
                        </div>
                    </FilterBar>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('accounting.counterparties.show', counterparty.id)}>{t('counterparties.statement.back')}</Link>
                        </Button>
                        <Button size="sm" onClick={() => window.print()}>
                            {t('counterparties.statement.print')}
                        </Button>
                    </div>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto grid w-full max-w-5xl gap-6 rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead
                        issuer={issuer}
                        title={t('counterparties.statement.title')}
                        subtitle={t('counterparties.statement.period', { from, to })}
                    />

                    <div className="text-sm">
                        <p className="font-semibold">{counterparty.legal_name}</p>
                        {counterparty.address && <p className="whitespace-pre-line">{counterparty.address}</p>}
                    </div>

                    {heads.length === 0 && <p className="py-6 text-center text-sm">{t('counterparties.statement.nothing')}</p>}

                    {heads.map((head) => (
                        <section key={head.slug} className="grid break-inside-avoid gap-2">
                            <h2 className="text-base">
                                <LedgerHead label={head.account} />
                            </h2>
                            <ReportTable
                                minWidth="48rem"
                                head={
                                    <>
                                        <th className="py-2">{t('counterparties.statement.col.date')}</th>
                                        <th className="py-2">{t('counterparties.statement.col.voucher')}</th>
                                        <th className="py-2">{t('counterparties.statement.col.particulars')}</th>
                                        <th className="py-2">{t('counterparties.statement.col.source')}</th>
                                        <th className="py-2 text-right">{t('counterparties.statement.col.debit')}</th>
                                        <th className="py-2 text-right">{t('counterparties.statement.col.credit')}</th>
                                        <th className="py-2 text-right">{t('counterparties.statement.col.balance')}</th>
                                    </>
                                }
                                foot={
                                    <tr className="font-bold">
                                        <td className="py-2" colSpan={4}>
                                            {t('counterparties.statement.closing')}
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={head.debits} tone="debit" className="font-bold" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={head.credits} tone="credit" className="font-bold" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={side(head.closing, head.closing_side)} tone="total" />
                                        </td>
                                    </tr>
                                }
                            >
                                <tr className="border-sidebar-border/70 border-b">
                                    <td className="py-2">
                                        <DateCell>{from}</DateCell>
                                    </td>
                                    <td className="py-2 font-semibold" colSpan={5}>
                                        {t('counterparties.statement.opening')}
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={side(head.opening, head.opening_side)} tone="balance" />
                                    </td>
                                </tr>
                                {head.lines.map((line, index) => (
                                    <tr key={index} className="border-sidebar-border/70 border-b">
                                        <td className="py-2">
                                            <DateCell>{showDate(line.date)}</DateCell>
                                        </td>
                                        <td className="py-2">
                                            <DocNo href={`/accounting/journals/${line.journal_id}`} className="text-xs">
                                                {line.journal_number}
                                            </DocNo>
                                        </td>
                                        <td className="py-2">{line.memo}</td>
                                        <td className="py-2">{line.source}</td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.debit} tone="debit" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.credit} tone="credit" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={side(line.balance, line.balance_side)} tone="balance" />
                                        </td>
                                    </tr>
                                ))}
                                {head.lines.length === 0 && <EmptyRow colSpan={7}>{t('counterparties.statement.no_entries')}</EmptyRow>}
                            </ReportTable>
                        </section>
                    ))}
                </div>
            </ReportPage>
        </AppLayout>
    );
}
