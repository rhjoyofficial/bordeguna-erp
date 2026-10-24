import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Commission earned, settled, paid and owed, by person and period (Joy's
 * Q18, 25 Oct 2026). Earned is on sales in the period; settled and paid are
 * what happened in the period; owed and to settle are as of today.
 */
interface Row {
    key: string;
    name: string;
    party_type: string;
    agreements: { id: number; number: string }[];
    earned: string;
    settled: string;
    paid: string;
    owed: string;
    to_settle: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Commission report', href: '/sales/commission-report' },
];

const sum = (rows: Row[], key: 'earned' | 'settled' | 'paid' | 'owed' | 'to_settle') => rows.reduce((total, row) => total + Number(row[key]), 0);

export default function CommissionReport({ from, to, rows }: { from: string; to: string; rows: Row[] }) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from, to });
    const columns = ['earned', 'settled', 'paid', 'owed', 'to_settle'] as const;
    const heading = {
        earned: 'commission.earned',
        settled: 'commission.settled',
        paid: 'commission.paid',
        owed: 'commission.owed_today',
        to_settle: 'commission.to_settle',
    } as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('commission.report_title')} />
            <div className="space-y-4 p-4">
                <p className="text-muted-foreground max-w-3xl text-sm">{t('commission.report_intro')}</p>

                <FormRow>
                    <Field label={t('commission.from')} htmlFor="cr-from">
                        <Input id="cr-from" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                    </Field>
                    <Field label={t('commission.until')} htmlFor="cr-to">
                        <Input id="cr-to" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
                    </Field>
                    <Button
                        type="button"
                        variant="outline"
                        className="self-end"
                        onClick={() => router.get('/sales/commission-report', range, { preserveState: false })}
                    >
                        {t('commission.show')}
                    </Button>
                </FormRow>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">{t('commission.person')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.number')}</th>
                                {columns.map((column) => (
                                    <th key={column} className="px-3 py-2 text-right font-medium">
                                        {t(heading[column])}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-3 py-4 text-center">
                                        {t('commission.no_rows')}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr key={row.key} className="border-t">
                                        <td className="px-3 py-2 font-medium">{row.name}</td>
                                        <td className="px-3 py-2">
                                            {row.agreements.map((a, i) => (
                                                <span key={a.id}>
                                                    {i > 0 && ', '}
                                                    <Link href={`/sales/commission-agreements/${a.id}`} className="hover:underline">
                                                        {a.number}
                                                    </Link>
                                                </span>
                                            ))}
                                        </td>
                                        {columns.map((column) => (
                                            <td key={column} className="px-3 py-2 text-right">
                                                {formatMoney(row[column])}
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="border-t font-semibold">
                                    <td className="px-3 py-2" colSpan={2}>
                                        {t('commission.total')}
                                    </td>
                                    {columns.map((column) => (
                                        <td key={column} className="px-3 py-2 text-right">
                                            {formatMoney(sum(rows, column))}
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
