import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * ⭐ B12 (25 Oct 2026): the end-of-shift (Z) summary of one cashier shift.
 *
 * Read-only, every figure from the server: the counter sales by payment
 * method, what stayed on account, the cash moves, and the drawer, expected
 * against counted. A shift still open shows the live expectation and no count.
 */
interface Movement {
    id: number;
    type: 'cash_in' | 'cash_out';
    kind: 'transfer' | 'expense' | null;
    amount: string;
    reason: string;
    document: string | null;
}

interface SummaryProps {
    shift: {
        id: number;
        status: 'open' | 'closed';
        till: string;
        cashier: string;
        closed_by: string | null;
        opened_at: string;
        closed_at: string | null;
        note: string | null;
    };
    sales: {
        count: number;
        voided: number;
        billed: string;
        by_method: { name: string; amount: string }[];
        on_account: string;
    };
    cash: {
        opening_float: string;
        ledger_in: string;
        ledger_out: string;
        moves_in: string;
        moves_out: string;
        notes_net: string;
        expected: string;
        counted: string | null;
        difference: string | null;
    };
    movements: Movement[];
}

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Row({ label, value, strong = false, tone }: { label: string; value: string; strong?: boolean; tone?: string }) {
    return (
        <div className={'flex items-baseline justify-between gap-6 py-1 ' + (strong ? 'font-semibold' : '')}>
            <span className={strong ? '' : 'text-muted-foreground'}>{label}</span>
            <span className={'tabular-nums ' + (tone ?? '')}>{value}</span>
        </div>
    );
}

export default function ShiftSummary({ shift, sales, cash, movements }: SummaryProps) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.sales'), href: '/sales/overview' },
        { title: t('shift.title'), href: '/sales/shifts' },
        { title: t('till.z_summary'), href: `/sales/shifts/${shift.id}/summary` },
    ];
    const difference = cash.difference === null ? null : Number(cash.difference);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('zsum.title')} />
            <div className="w-fit max-w-full space-y-5 p-4 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">
                        {t('zsum.title')}: {shift.till}
                    </h1>
                    <div className="flex gap-2 print:hidden">
                        <Button type="button" variant="outline" size="sm" onClick={() => window.print()}>
                            {t('zsum.print')}
                        </Button>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/sales/shifts">{t('zsum.back')}</Link>
                        </Button>
                    </div>
                </div>

                <section className="border-border rounded-lg border p-4">
                    <Row label={t('zsum.cashier')} value={shift.cashier} />
                    <Row label={t('zsum.opened')} value={showDate(shift.opened_at)} />
                    {shift.closed_at !== null && <Row label={t('zsum.closed')} value={`${showDate(shift.closed_at)} (${shift.closed_by ?? ''})`} />}
                    {shift.status === 'open' && <p className="mt-2 text-amber-700 dark:text-amber-400">{t('zsum.still_open')}</p>}
                    {shift.note && <p className="text-muted-foreground mt-2">{shift.note}</p>}
                </section>

                <section className="border-border rounded-lg border p-4">
                    <h2 className="mb-2 font-semibold">{t('zsum.sales')}</h2>
                    {sales.count === 0 && sales.voided === 0 ? (
                        <p className="text-muted-foreground">{t('zsum.none')}</p>
                    ) : (
                        <>
                            <Row label={t('zsum.count')} value={String(sales.count)} />
                            {sales.voided > 0 && <Row label={t('zsum.voided')} value={String(sales.voided)} />}
                            <Row label={t('zsum.billed')} value={money(sales.billed)} strong />
                            <p className="text-muted-foreground mt-2 text-xs font-medium">{t('zsum.by_method')}</p>
                            {sales.by_method.map((method) => (
                                <Row key={method.name} label={method.name} value={money(method.amount)} />
                            ))}
                            <Row label={t('zsum.on_account')} value={money(sales.on_account)} />
                        </>
                    )}
                </section>

                <section className="border-border rounded-lg border p-4">
                    <h2 className="mb-2 font-semibold">{t('zsum.cash')}</h2>
                    <Row label={t('zsum.float')} value={money(cash.opening_float)} />
                    <Row label={t('zsum.ledger_in')} value={money(cash.ledger_in)} />
                    <Row label={t('zsum.moves_in')} value={money(cash.moves_in)} />
                    <Row label={t('zsum.ledger_out')} value={'-' + money(cash.ledger_out)} />
                    <Row label={t('zsum.moves_out')} value={'-' + money(cash.moves_out)} />
                    {Number(cash.notes_net) !== 0 && <Row label={t('zsum.notes')} value={money(cash.notes_net)} />}
                    <Row label={t('zsum.expected')} value={money(cash.expected)} strong />
                    <Row label={t('zsum.counted')} value={cash.counted === null ? t('zsum.not_counted') : money(cash.counted)} strong />
                    {difference !== null && (
                        <Row
                            label={t('zsum.difference')}
                            value={money(difference)}
                            strong
                            tone={difference === 0 ? 'text-emerald-600' : 'text-destructive'}
                        />
                    )}
                </section>

                {movements.length > 0 && (
                    <section className="border-border rounded-lg border p-4">
                        <h2 className="mb-2 font-semibold">{t('zsum.moves')}</h2>
                        <table>
                            <tbody>
                                {movements.map((movement) => (
                                    <tr key={movement.id} className="border-t">
                                        <td className="py-1.5 pr-4">{movement.type === 'cash_in' ? t('shift.in') : t('shift.out')}</td>
                                        <td className="py-1.5 pr-4 text-right tabular-nums">
                                            {movement.type === 'cash_in' ? '' : '-'}
                                            {money(movement.amount)}
                                        </td>
                                        <td className="text-muted-foreground py-1.5 pr-4">{movement.reason}</td>
                                        <td className="text-muted-foreground py-1.5 text-xs">
                                            {movement.document ? t('till.posted_as', { number: movement.document }) : t('till.note_only')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
