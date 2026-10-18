import { Chip } from '@/components/assets/assets-ui';
import { useTermsLabel } from '@/components/purchasing/payment-terms-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { type PaymentTerms } from '@/lib/payment-terms';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type Group = 'overdue' | 'today' | 'soon';

type DueRow = {
    id: number;
    bill_number: string;
    supplier: string | null;
    supplier_id: number;
    bill_date: string | null;
    due_date: string;
    no_due_date: boolean;
    terms: PaymentTerms | null;
    /** Days until the due date; negative once it has passed. */
    days: number;
    outstanding: string;
    group: Group;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
    { title: 'Bills due', href: '/purchasing/bills-due' },
];

const WINDOWS = [7, 14, 30];

const TITLE: Record<Group, TranslationKey> = { overdue: 'due.overdue', today: 'due.today', soon: 'due.soon' };

/**
 * ⭐ Ruling P6: supplier bills by due date, overdue first (the latest
 * worst), then due today, then due within the window. Each due date is the
 * one stored on the bill when it was recorded.
 */
export default function BillsDue({
    rows,
    days,
    groups,
    can_pay,
}: {
    rows: DueRow[];
    days: number;
    groups: Record<Group, { count: number; total: string }>;
    can_pay: boolean;
}) {
    const { t } = useTranslation();
    const termsLabel = useTermsLabel();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('due.title')} />

            <div className="flex flex-col gap-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{t('due.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('due.intro')}</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/purchasing/bills">{t('due.all_bills')}</Link>
                    </Button>
                </div>

                <div role="group" aria-label={t('due.window')} className="flex flex-wrap items-center gap-1.5">
                    <span className="text-muted-foreground mr-1 text-sm">{t('due.window')}</span>
                    {WINDOWS.map((window) => (
                        <Chip
                            key={window}
                            active={days === window}
                            onClick={() => router.get('/purchasing/bills-due', { days: window }, { preserveScroll: true })}
                        >
                            {t('due.window_days', { days: window })}
                        </Chip>
                    ))}
                </div>

                {rows.length === 0 && <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">{t('due.none')}</p>}

                {(['overdue', 'today', 'soon'] as Group[])
                    .filter((group) => groups[group].count > 0)
                    .map((group) => (
                        <section key={group} className="bg-card overflow-hidden rounded-xl border" data-group={group}>
                            <h2
                                className={`flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-sm font-semibold ${group === 'overdue' ? 'text-destructive' : ''}`}
                            >
                                <span>
                                    {t(TITLE[group], { days })} ({groups[group].count})
                                </span>
                                <span className="tabular-nums">{formatMoney(groups[group].total)}</span>
                            </h2>
                            <div className="overflow-x-auto">
                                <table className="w-full text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                                    <thead className="text-muted-foreground">
                                        <tr>
                                            <th>{t('due.bill')}</th>
                                            <th>{t('due.supplier')}</th>
                                            <th>{t('due.terms')}</th>
                                            <th>{t('due.due_date')}</th>
                                            <th>{t('due.when')}</th>
                                            <th className="text-right!">{t('due.outstanding')}</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows
                                            .filter((row) => row.group === group)
                                            .map((row) => (
                                                <tr key={row.id} className="hover:bg-muted/40">
                                                    <td>
                                                        <Link href={`/purchasing/bills/${row.id}`} className="font-medium hover:underline">
                                                            {row.bill_number}
                                                        </Link>
                                                        <div className="text-muted-foreground text-xs">
                                                            {t('due.bill_date')}: {showDate(row.bill_date)}
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <Link href={`/purchasing/suppliers/${row.supplier_id}`} className="hover:underline">
                                                            {row.supplier ?? '-'}
                                                        </Link>
                                                    </td>
                                                    <td className="text-muted-foreground">
                                                        {row.terms ? termsLabel(row.terms.type, row.terms.days) : '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap">
                                                        {showDate(row.due_date)}
                                                        {row.no_due_date && (
                                                            <div className="text-muted-foreground text-xs">{t('due.no_due_date')}</div>
                                                        )}
                                                    </td>
                                                    <td className={`whitespace-nowrap ${row.days < 0 ? 'text-destructive font-medium' : ''}`}>
                                                        {row.days < 0 && t('due.days_late', { days: -row.days })}
                                                        {row.days === 0 && t('due.today')}
                                                        {row.days > 0 && t('due.days_left', { days: row.days })}
                                                    </td>
                                                    <td className="text-right font-medium tabular-nums">{formatMoney(row.outstanding)}</td>
                                                    <td className="text-right">
                                                        {can_pay && (
                                                            <Button size="sm" variant="outline" className="h-7" asChild>
                                                                <Link href={route('purchasing.payments.create', { bill_id: row.id })}>
                                                                    {t('due.pay')}
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}
            </div>
        </AppLayout>
    );
}
