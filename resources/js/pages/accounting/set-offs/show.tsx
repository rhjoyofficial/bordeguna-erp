import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface DocLine {
    number: string | null;
    date: string | null;
    amount: string;
}

interface SetOffDoc {
    id: number;
    number: string;
    date: string | null;
    supplier: { id: number; name: string | null; code: string | null };
    customer: { id: number; name: string | null };
    amount: string;
    status: 'pending' | 'posted' | 'rejected' | 'reversed';
    status_label: string;
    reason: string;
    raised_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    journal: string | null;
    reversal: { on: string | null; by: string | null; reason: string | null; journal: string | null } | null;
    decisions: { by: string | null; decision: string; note: string | null; at: string | null; self_approved: boolean }[];
    bills: DocLine[];
    invoices: DocLine[];
}

function Lines({ title, rows }: { title: string; rows: DocLine[] }) {
    const { t } = useTranslation();

    return (
        <div>
            <p className="mb-1 text-sm font-medium">{title}</p>
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-black/20 text-left">
                        <th className="py-1">{t('set_off.number')}</th>
                        <th className="py-1">{t('set_off.date')}</th>
                        <th className="py-1 text-right">{t('set_off.amount')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b border-black/10">
                            <td className="py-1">{row.number}</td>
                            <td className="py-1">{formatDate(row.date)}</td>
                            <td className="py-1 text-right tabular-nums">{row.amount}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * ⭐ A set-off as a paper (decision D-F, 18 Oct 2026): the party, the bills and
 * the invoices it clears, the books, and who raised and approved it. Printed
 * for the file and, when the party asks, for them to acknowledge.
 */
export default function SetOffShow({ issuer, setOff }: { issuer: Issuer; setOff: SetOffDoc }) {
    const { t, tr } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: t('set_off.title'), href: '/accounting/set-offs' },
        { title: setOff.number, href: `/accounting/set-offs/${setOff.id}` },
    ];
    const stamp = setOff.status === 'posted' ? null : tr(`set_off.status_${setOff.status}`, setOff.status_label);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={setOff.number} />
            <div className="flex flex-col gap-4 p-4 print:p-0">
                <div className="flex flex-wrap justify-between gap-2 print:hidden">
                    <Button asChild size="sm" variant="outline">
                        <Link href="/accounting/set-offs">{t('set_off.back')}</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        {t('set_off.print')}
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title={t('set_off.document_title')} subtitle={`No. ${setOff.number}`} />

                    {stamp && (
                        <p className="mt-2 w-fit border-2 border-red-700 px-3 py-1 text-sm font-bold tracking-widest text-red-700 uppercase">
                            {stamp}
                        </p>
                    )}

                    <div className="mt-4 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">{t('set_off.supplier')}</p>
                            <p className="font-medium">
                                {setOff.supplier.name}
                                {setOff.supplier.code ? ` (${setOff.supplier.code})` : ''}
                            </p>
                            <p className="mt-2 text-black/60">{t('set_off.customer')}</p>
                            <p className="font-medium">{setOff.customer.name}</p>
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">{t('set_off.date')}: </span>
                                {formatDate(setOff.date)}
                            </p>
                            <p>
                                <span className="text-black/60">{t('set_off.amount')}: </span>
                                <span className="font-semibold">BDT {setOff.amount}</span>
                            </p>
                            {setOff.journal && (
                                <p>
                                    <span className="text-black/60">{t('set_off.journal')}: </span>
                                    {setOff.journal}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-6 grid gap-6 sm:grid-cols-2">
                        <Lines title={t('set_off.bills_cleared')} rows={setOff.bills} />
                        <Lines title={t('set_off.invoices_cleared')} rows={setOff.invoices} />
                    </div>

                    <p className="mt-4 text-sm">
                        <span className="text-black/60">{t('set_off.reason')}: </span>
                        {setOff.reason}
                    </p>
                    <p className="mt-2 text-xs text-black/60">
                        {t('set_off.books', { supplier: setOff.supplier.name ?? '', customer: setOff.customer.name ?? '' })}
                    </p>

                    {setOff.reversal && (
                        <p className="mt-2 text-sm font-medium text-red-700">
                            {t('set_off.reversed_note', {
                                date: setOff.reversal.on ?? '',
                                by: setOff.reversal.by ?? '',
                                reason: setOff.reversal.reason ?? '',
                            })}
                            {setOff.reversal.journal ? ` (${setOff.reversal.journal})` : ''}
                        </p>
                    )}

                    {setOff.decisions.length > 0 && (
                        <div className="mt-4 text-xs">
                            <p className="font-medium">{t('set_off.approvals')}</p>
                            {setOff.decisions.map((d, i) => (
                                <p key={i} className="text-black/70">
                                    {d.at} · {d.by} · {d.decision}
                                    {d.self_approved ? ` (${t('set_off.self_approved')})` : ''}
                                    {d.note ? `: ${d.note}` : ''}
                                </p>
                            ))}
                        </div>
                    )}

                    <div className="mt-12 flex justify-between gap-8 text-sm">
                        <div className="flex-1">
                            <div className="border-t border-black/40 pt-1">
                                {t('set_off.signature_prepared')}
                                {setOff.raised_by ? `: ${setOff.raised_by}` : ''}
                            </div>
                        </div>
                        <div className="flex-1">
                            <div className="border-t border-black/40 pt-1">
                                {t('set_off.signature_approved')}
                                {setOff.posted_by ? `: ${setOff.posted_by}` : ''}
                            </div>
                        </div>
                        <div className="flex-1">
                            <div className="border-t border-black/40 pt-1">{t('set_off.signature_party')}</div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
