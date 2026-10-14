import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { FilterBar } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Balance {
    label: string;
    amount: string;
    /** A debit is owed to us by the counterparty; a credit is owed by us. */
    owed_by: 'counterparty' | 'business';
}

/**
 * The balance confirmation letter (ChatGPT review, point 6): what our books
 * say each side owes as at a date, on the letterhead, with a reply block for
 * the counterparty to sign and return. The auditor's standard request, and the
 * accountant's proof that a loan balance is agreed by both sides.
 *
 * ⚠ Browser print is the PDF, as for every document here (Chromium shapes
 * Bangla names; dompdf does not).
 */
export default function CounterpartyConfirmation({
    as_at,
    counterparty,
    issuer,
    balances,
}: {
    as_at: string;
    counterparty: { id: number; legal_name: string; address: string | null };
    issuer: Issuer;
    balances: Balance[];
}) {
    const { t } = useTranslation();
    const [asAt, setAsAt] = useState(as_at);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('counterparties.title'), href: '/accounting/counterparties' },
        { title: counterparty.legal_name, href: route('accounting.counterparties.show', counterparty.id) },
        { title: t('counterparties.confirm.title'), href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.counterparties.confirmation', counterparty.id), { as_at: asAt }, { preserveState: true });
    };

    const signatory = [issuer.signatory_name, issuer.signatory_title].filter(Boolean).join(', ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t('counterparties.confirm.title')}: ${counterparty.legal_name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3 print:hidden">
                    <FilterBar onSubmit={submit} label={t('counterparties.confirm.show')}>
                        <div className="grid gap-2">
                            <Label htmlFor="as_at">{t('counterparties.confirm.as_at')}</Label>
                            <Input id="as_at" type="date" value={asAt} onChange={(e) => setAsAt(e.target.value)} />
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

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto grid w-full max-w-3xl gap-5 rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title={t('counterparties.confirm.title')} />

                    <p className="text-right">{t('counterparties.confirm.date', { date: as_at })}</p>

                    <div>
                        <p className="text-xs text-gray-600">{t('counterparties.confirm.to')}</p>
                        <p className="font-semibold">{counterparty.legal_name}</p>
                        {counterparty.address && <p className="whitespace-pre-line">{counterparty.address}</p>}
                    </div>

                    <p>{t('counterparties.confirm.salutation')}</p>
                    <p>{t('counterparties.confirm.intro', { business: issuer.legal_name ?? issuer.name, date: as_at })}</p>

                    {balances.length === 0 ? (
                        <p className="font-medium">{t('counterparties.confirm.nil')}</p>
                    ) : (
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="bg-gray-100 text-left">
                                    <th className="border border-gray-300 px-3 py-2">{t('counterparties.confirm.col.head')}</th>
                                    <th className="border border-gray-300 px-3 py-2 text-right">{t('counterparties.confirm.col.amount')}</th>
                                    <th className="border border-gray-300 px-3 py-2">{t('counterparties.confirm.col.owed_by')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {balances.map((balance) => (
                                    <tr key={balance.label}>
                                        <td className="border border-gray-300 px-3 py-2">{balance.label}</td>
                                        <td className="border border-gray-300 px-3 py-2 text-right font-semibold tabular-nums">{balance.amount}</td>
                                        <td className="border border-gray-300 px-3 py-2">
                                            {balance.owed_by === 'counterparty'
                                                ? t('counterparties.confirm.owed_by_you')
                                                : t('counterparties.confirm.owed_by_us')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <p>{t('counterparties.confirm.request')}</p>
                    <p className="text-gray-700">{t('counterparties.confirm.not_demand')}</p>

                    <div className="mt-6 grid gap-1">
                        <p>{t('counterparties.confirm.for_business', { business: issuer.legal_name ?? issuer.name })}</p>
                        <div className="mt-10 w-64 border-t border-gray-500 pt-1">
                            <p>{t('counterparties.confirm.signature')}</p>
                            {signatory && <p className="text-gray-700">{signatory}</p>}
                        </div>
                    </div>

                    <div className="mt-6 grid break-inside-avoid gap-3 border-t border-dashed border-gray-400 pt-4">
                        <p className="font-semibold">{t('counterparties.confirm.reply_title')}</p>
                        <p>{t('counterparties.confirm.reply_body')}</p>
                        <div className="grid gap-1">
                            <p className="text-gray-700">{t('counterparties.confirm.differences')}</p>
                            <div className="h-16 border-b border-gray-400" />
                        </div>
                        <p>{t('counterparties.confirm.for_counterparty', { name: counterparty.legal_name })}</p>
                        <div className="mt-8 w-72 border-t border-gray-500 pt-1">
                            <p>{t('counterparties.confirm.sign_lines')}</p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
