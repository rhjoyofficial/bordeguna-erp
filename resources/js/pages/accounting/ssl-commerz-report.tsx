import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Joy's answer 9 (25 Oct 2026): Accounts > Reports > SSL Commerz. For a period:
 * what reached SSL Commerz Clearing, what SSL settled, its charge, the net to
 * the bank and what is still in clearing. Excel downloads the same figures.
 */
type Report = {
    clearing: string | null;
    from: string;
    to: string;
    opening: number;
    received: number;
    settled_gross: number;
    fee: number;
    net: number;
    closing: number;
    settlements: {
        id: number;
        number: string;
        settled_on: string;
        bank: string | null;
        reference: string | null;
        gross: string;
        fee: string;
        net: string;
    }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SSL Commerz', href: '/accounting/reports/ssl-commerz' }];

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function SslCommerzReport({ report }: { report: Report }) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const rows: [string, number, boolean][] = [
        [t('ipa.ssl.opening'), report.opening, false],
        [t('ipa.ssl.received'), report.received, false],
        [t('ipa.ssl.settled'), report.settled_gross, false],
        [t('ipa.ssl.fee_taken'), report.fee, false],
        [t('ipa.ssl.net_bank'), report.net, true],
        [t('ipa.ssl.closing'), report.closing, true],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('ipa.ssl.report_title')} />
            <div className="flex flex-col gap-4 p-4">
                <ReportHeading title={t('ipa.ssl.report_title')} description={t('ipa.ssl.report_intro')} />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-from">{t('ipa.ssl.from')}</Label>
                        <Input id="ssl-from" type="date" className="w-fit" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ssl-to">{t('ipa.ssl.to')}</Label>
                        <Input id="ssl-to" type="date" className="w-fit" value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get(route('accounting.reports.ssl-commerz'), { from, to }, { preserveState: true })}
                    >
                        {t('ipa.ssl.show')}
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <a href={route('accounting.reports.ssl-commerz.export', { from: report.from, to: report.to })}>{t('ipa.ssl.excel')}</a>
                    </Button>
                </div>

                {report.clearing === null ? (
                    <p className="text-sm">{t('ipa.ssl.no_clearing')}</p>
                ) : (
                    <section className="w-fit max-w-full rounded-lg border p-4 text-sm">
                        {rows.map(([label, value, strong]) => (
                            <div key={label} className={'flex items-baseline justify-between gap-10 py-1 ' + (strong ? 'font-semibold' : '')}>
                                <span className={strong ? '' : 'text-muted-foreground'}>{label}</span>
                                <span className="tabular-nums">{taka(value)}</span>
                            </div>
                        ))}
                    </section>
                )}

                {report.settlements.length > 0 && (
                    <div className="w-fit max-w-full overflow-x-auto rounded-lg border">
                        <table className="text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('ipa.ssl.col_number')}</th>
                                    <th className="p-3">{t('ipa.ssl.col_date')}</th>
                                    <th className="p-3">{t('ipa.ssl.col_bank')}</th>
                                    <th className="p-3">{t('ipa.ssl.col_reference')}</th>
                                    <th className="p-3 text-right">{t('ipa.ssl.col_gross')}</th>
                                    <th className="p-3 text-right">{t('ipa.ssl.col_fee')}</th>
                                    <th className="p-3 text-right">{t('ipa.ssl.col_net')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.settlements.map((s) => (
                                    <tr key={s.id} className="border-t">
                                        <td className="p-3">{s.number}</td>
                                        <td className="p-3">{showDate(s.settled_on)}</td>
                                        <td className="p-3">{s.bank ?? '-'}</td>
                                        <td className="text-muted-foreground p-3">{s.reference ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{money(s.gross)}</td>
                                        <td className="p-3 text-right tabular-nums">{money(s.fee)}</td>
                                        <td className="p-3 text-right tabular-nums">{money(s.net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
