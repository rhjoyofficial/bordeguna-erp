import { type AgreementRow, CommissionAgreementDialog, type CommissionOptions } from '@/components/sales/commission-agreement-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Commission agreements (Joy's Q18, 25 Oct 2026): it replaces the Targets
 * tab. Nobody earns commission without an agreement here; each row shows what
 * the person has sold under it, earned, settled and what is still to settle.
 * The old target rows stay readable at the bottom and pay nothing.
 */
interface LegacyTarget {
    id: number;
    month: string;
    user: string | null;
    target_amount: string;
    commission_percent: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Commission agreements', href: '/sales/commission-agreements' },
];

export default function CommissionAgreements({
    agreements,
    options,
    legacyTargets,
}: {
    agreements: AgreementRow[];
    options: CommissionOptions;
    legacyTargets: LegacyTarget[];
}) {
    const { t } = useTranslation();
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('commission.title')} />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('commission.intro')}</p>
                    <Button size="sm" onClick={() => setAdding(true)}>
                        {t('commission.new')}
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">{t('commission.number')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.person')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.deal')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.dates')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.sold')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.earned')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.settled')}</th>
                                <th className="px-3 py-2 text-right font-medium">{t('commission.to_settle')}</th>
                                <th className="px-3 py-2 font-medium">{t('commission.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {agreements.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground px-3 py-4 text-center">
                                        {t('commission.none_yet')}
                                    </td>
                                </tr>
                            ) : (
                                agreements.map((a) => (
                                    <tr key={a.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">
                                            <Link href={`/sales/commission-agreements/${a.id}`} className="underline-offset-2 hover:underline">
                                                {a.number}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">{a.party_name}</td>
                                        <td className="px-3 py-2">{a.deal_summary}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {showDate(a.valid_from)} {t('commission.to')}{' '}
                                            {a.valid_to ? showDate(a.valid_to) : t('commission.open_end')}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {a.progress ? (
                                                <>
                                                    {t('commission.units', { qty: formatQuantity(a.progress.sold_quantity) })}
                                                    <div className="text-muted-foreground text-xs">Tk {formatMoney(a.progress.sold_value)}</div>
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right">{a.progress ? formatMoney(a.progress.earned) : '-'}</td>
                                        <td className="px-3 py-2 text-right">{a.progress ? formatMoney(a.progress.settled) : '-'}</td>
                                        <td className="px-3 py-2 text-right font-medium">{a.progress ? formatMoney(a.progress.to_settle) : '-'}</td>
                                        <td className="px-3 py-2">{a.status_label}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {legacyTargets.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-semibold">{t('commission.legacy_title')}</h2>
                        <p className="text-muted-foreground text-xs">{t('commission.legacy_hint')}</p>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left">
                                        <th className="px-3 py-2 font-medium">{t('commission.legacy_month')}</th>
                                        <th className="px-3 py-2 font-medium">{t('commission.person')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('commission.legacy_target')}</th>
                                        <th className="px-3 py-2 text-right font-medium">{t('commission.legacy_percent')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {legacyTargets.map((target) => (
                                        <tr key={target.id} className="border-t">
                                            <td className="px-3 py-2">{target.month}</td>
                                            <td className="px-3 py-2">{target.user ?? '-'}</td>
                                            <td className="px-3 py-2 text-right">{formatMoney(target.target_amount)}</td>
                                            <td className="px-3 py-2 text-right">{target.commission_percent}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <CommissionAgreementDialog open={adding} onOpenChange={setAdding} options={options} />
            </div>
        </AppLayout>
    );
}
