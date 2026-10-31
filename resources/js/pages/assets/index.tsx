import { type AssetFigures, FixedAssetCorrections } from '@/components/assets/fixed-asset-corrections';
import { FixedAssetDialog } from '@/components/assets/fixed-asset-dialog';
import { Pagination } from '@/components/data-table/pagination';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Asset {
    id: number;
    asset_code: string;
    name: string;
    /** ⚠ A plain Y-m-d from the server; the raw date cast serialises in UTC. */
    acquired_on: string | null;
    account: string | null;
    paid_from: string | null;
    supplier: string | null;
    cost: string;
    salvage_value: string;
    useful_life_months: number;
    depreciation_starts_on: string | null;
    accumulated: string;
    /** FEA-004: the part of `accumulated` written off before cutover, per the opening TB. */
    opening_accumulated: string;
    remaining_life_months: number | null;
    opening_as_of: string | null;
    book_value: string;
    monthly_charge: string;
    is_fully_depreciated: boolean;
    // ⚠ Null: the cost is not in the books, so it is not depreciated.
    cost_on_books: 'acquisition_journal' | 'opening_balance' | null;
    note: string | null;
    // ⭐ Corrections wave 2 (24 Oct 2026): Reverse and Dispose, and their marks.
    state: 'held' | 'reversed' | 'disposed';
    reversed: { on: string | null; by: string | null; reason: string | null } | null;
    disposed: { on: string | null; by: string | null; method: string | null; proceeds: string; reason: string | null } | null;
    figures: AssetFigures;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Fixed assets', href: '/assets' },
];

export default function AssetsIndex({
    assets,
    canManage,
    assetAccounts,
    moneyAccounts,
    suppliers,
    today,
    categories,
    cutoverDate,
    canRecordOpening,
    correctionReasons = {},
    gainLossHeads = [],
    canCorrect = false,
}: {
    assets: Paginated<Asset>;
    canManage: boolean;
    assetAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    suppliers: { id: number; name: string }[];
    today: string;
    categories: { id: number; name: string; code: string; default_useful_life_months: number | null }[];
    cutoverDate: string;
    canRecordOpening: boolean;
    correctionReasons?: Record<string, ReasonOption[]>;
    gainLossHeads?: { id: number; label: string; type: string }[];
    canCorrect?: boolean;
}) {
    const { t } = useTranslation();
    // ⭐ Record an asset opens a popup over the register (Joy, 14 Oct 2026:
    // "click add button pop up will add"). Add only, as before.
    const [recording, setRecording] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fixed assets" />
            <div className="flex flex-col gap-4 p-4">
                {/* The tab row above names the screen; only the action sits here (15 Oct 2026). */}
                <div className="flex flex-wrap items-center justify-end gap-3">
                    {canManage && (
                        <Button size="sm" onClick={() => setRecording(true)}>
                            Record asset
                        </Button>
                    )}
                </div>

                <div className="bg-card overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[64rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Code</th>
                                <th className="p-3">Asset</th>
                                <th className="p-3">Acquired</th>
                                <th className="p-3">Reports under</th>
                                <th className="p-3 text-right">Cost</th>
                                <th className="p-3 text-right">Written off</th>
                                <th className="p-3 text-right">Book value</th>
                                <th className="p-3 text-right">Per month</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {assets.data.map((asset) => (
                                <tr key={asset.id} className={`border-t ${asset.state === 'held' ? '' : 'text-muted-foreground'}`}>
                                    <td className="p-3 font-medium">{asset.asset_code}</td>
                                    <td className="p-3">
                                        {asset.name}
                                        {asset.is_fully_depreciated && (
                                            <Badge variant="secondary" className="ml-2">
                                                Fully written off
                                            </Badge>
                                        )}
                                        {asset.opening_as_of !== null && (
                                            <Badge variant="outline" className="ml-2">
                                                {t('asset.opening.badge', {
                                                    amount: asset.opening_accumulated,
                                                    months: asset.remaining_life_months ?? '-',
                                                })}
                                            </Badge>
                                        )}
                                        {asset.reversed && (
                                            <span className="mt-1 block text-xs">
                                                <Badge variant="outline">{t('corr.people.asset.reversed')}</Badge>{' '}
                                                {t('corr.people.reversed_on', { date: formatDate(asset.reversed.on) })} {asset.reversed.reason}
                                            </span>
                                        )}
                                        {asset.disposed && (
                                            <span className="mt-1 block text-xs">
                                                <Badge variant="outline">{t('corr.people.asset.disposed')}</Badge> {formatDate(asset.disposed.on)} ·{' '}
                                                {asset.disposed.method} · {formatMoney(asset.disposed.proceeds)} · {asset.disposed.reason}
                                            </span>
                                        )}
                                        {asset.cost_on_books === null && (
                                            <Badge variant="outline" className="ml-2">
                                                Cost not in the books, not depreciated
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{formatDate(asset.acquired_on)}</td>
                                    <td className="text-muted-foreground p-3">{asset.account ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(asset.cost)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(asset.accumulated)}</td>
                                    <td className="p-3 text-right font-medium tabular-nums">{formatMoney(asset.book_value)}</td>
                                    <td className="text-muted-foreground p-3 text-right tabular-nums">
                                        {asset.state === 'held' ? formatMoney(asset.monthly_charge) : '-'}
                                    </td>
                                    <td className="p-3">
                                        {canCorrect && (
                                            <FixedAssetCorrections
                                                asset={asset}
                                                reasons={correctionReasons}
                                                moneyAccounts={moneyAccounts}
                                                heads={gainLossHeads}
                                                today={today}
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {assets.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                        Nothing recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={assets} />

                {canManage && (
                    <FixedAssetDialog
                        open={recording}
                        onOpenChange={setRecording}
                        assetAccounts={assetAccounts}
                        moneyAccounts={moneyAccounts}
                        suppliers={suppliers}
                        today={today}
                        categories={categories}
                        cutoverDate={cutoverDate}
                        canRecordOpening={canRecordOpening}
                    />
                )}
            </div>
        </AppLayout>
    );
}
