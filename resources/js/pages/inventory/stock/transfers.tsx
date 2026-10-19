import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { BlockedNote, StockReasonDialog, type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { decimalInput, formatQuantity } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface TransferRow {
    id: number;
    quantity: string;
    status: 'in_transit' | 'received' | 'cancelled';
    note: string | null;
    product: { name: string; sku: string };
    source_warehouse: { name: string };
    target_warehouse: { name: string };
    reason: { name: string };
    dispatched_by: { name: string };
    dispatched_at: string;
    received_by: { name: string } | null;
    received_at: string | null;
    // ⭐ Corrections wave 1 (20 Oct 2026).
    cancel_reason: string | null;
    shortage_quantity: string | null;
    shortage_reason: string | null;
    actions: StockAvailability[];
}

type Reason = { id: number; name: string };

const breadcrumbs = inventoryCrumbs('Stock', 'Transfers');

const statusVariant: Record<TransferRow['status'], 'secondary' | 'default' | 'outline'> = {
    in_transit: 'secondary',
    received: 'default',
    cancelled: 'outline',
};

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): "Not all of it arrived?" on a
 * transfer on the road. How many came, what happened to the rest, and why;
 * the rest becomes a stock correction (damage or loss) with that reason.
 */
function ReceivePartDialog({
    transfer,
    lossReasons,
    correctionReasons,
}: {
    transfer: TransferRow;
    lossReasons: Reason[];
    correctionReasons: Reason[];
}) {
    const { t } = useTranslation();
    const [received, setReceived] = useState('');
    const [loss, setLoss] = useState('');

    return (
        <StockReasonDialog
            trigger={
                <button type="button" className="text-muted-foreground text-xs underline underline-offset-2">
                    {t('corr.stock.transfer.not_all')}
                </button>
            }
            title={t('corr.stock.transfer.part_title', { id: transfer.id })}
            description={t('corr.stock.transfer.part_explain', { quantity: formatQuantity(transfer.quantity) })}
            confirmLabel={t('corr.stock.transfer.part_confirm')}
            reasons={correctionReasons}
            url={route('inventory.stock.transfers.receive-part', transfer.id)}
            data={{ received_quantity: received, loss_reason_id: loss === '' ? null : Number(loss) }}
            ready={received !== '' && loss !== ''}
        >
            <div className="grid gap-2">
                <Label htmlFor={`received-${transfer.id}`} required>
                    {t('corr.stock.transfer.how_many_arrived')}
                </Label>
                <Input
                    id={`received-${transfer.id}`}
                    inputMode="decimal"
                    className="w-32"
                    value={received}
                    onChange={(e) => setReceived(decimalInput(e.target.value))}
                />
                <Label htmlFor={`loss-${transfer.id}`} required>
                    {t('corr.stock.transfer.what_happened')}
                </Label>
                <SearchSelect id={`loss-${transfer.id}`} value={loss} onChange={(e) => setLoss(e.target.value)}>
                    <option value="">{t('corr.stock.transfer.choose_loss')}</option>
                    {lossReasons.map((reason) => (
                        <option key={reason.id} value={reason.id}>
                            {reason.name}
                        </option>
                    ))}
                </SearchSelect>
            </div>
        </StockReasonDialog>
    );
}

export default function StockTransfersIndex({
    transfers,
    can_receive,
    filters: initialFilters,
    cancelReasons,
    correctionReasons,
    lossReasons,
}: {
    transfers: Paginated<TransferRow>;
    can_receive: boolean;
    filters: { status: string };
    cancelReasons: Reason[];
    correctionReasons: Reason[];
    lossReasons: Reason[];
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock/transfers', initialFilters);

    const receive = (transfer: TransferRow) => {
        router.post(route('inventory.stock.transfers.receive', transfer.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transfers" />
            <div className="flex flex-col gap-4 p-4">
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Status" htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="in_transit">In transit</option>
                            <option value="received">Received</option>
                            <option value="cancelled">{t('corr.stock.transfer.cancelled')}</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                {/* INV-007 (14 Oct 2026): scrolls inside itself at phone width, so the
                    action column stays reachable, like every other inventory table. */}
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">Product</th>
                                <th className="py-2">Route</th>
                                <th className="py-2">Qty</th>
                                <th className="py-2">Reason</th>
                                <th className="py-2">Dispatched</th>
                                <th className="py-2">Status</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.map((transfer) => {
                                const cancel = transfer.actions.find((a) => a.action === 'cancel');

                                return (
                                    <tr key={transfer.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                        <td className="py-2">
                                            {transfer.product.name} <span className="text-muted-foreground">({transfer.product.sku})</span>
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {transfer.source_warehouse.name} → {transfer.target_warehouse.name}
                                        </td>
                                        <td className="py-2">{formatQuantity(transfer.quantity)}</td>
                                        <td className="text-muted-foreground max-w-xs py-2">
                                            {transfer.reason.name}
                                            {transfer.note && <div className="text-xs">{transfer.note}</div>}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {transfer.dispatched_by.name}
                                            <div className="text-xs">{showDate(transfer.dispatched_at)}</div>
                                        </td>
                                        <td className="max-w-xs py-2">
                                            <Badge variant={statusVariant[transfer.status]} className="capitalize">
                                                {transfer.status.replace('_', ' ')}
                                            </Badge>
                                            {transfer.cancel_reason && (
                                                <div className="text-muted-foreground mt-1 text-xs">{transfer.cancel_reason}</div>
                                            )}
                                            {transfer.shortage_quantity && (
                                                <div className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                    {t('corr.stock.transfer.short_by', { quantity: formatQuantity(transfer.shortage_quantity) })}{' '}
                                                    {transfer.shortage_reason}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            {transfer.status === 'in_transit' && can_receive && (
                                                <div className="inline-flex flex-col items-end gap-1">
                                                    <div>
                                                        <Button variant="ghost" size="sm" onClick={() => receive(transfer)}>
                                                            Mark received
                                                        </Button>
                                                        {cancel && (
                                                            <StockReasonDialog
                                                                trigger={
                                                                    <Button variant="ghost" size="sm" disabled={!cancel.allowed}>
                                                                        {t('corr.stock.cancel')}
                                                                    </Button>
                                                                }
                                                                title={t('corr.stock.transfer.cancel_title', { id: transfer.id })}
                                                                description={t('corr.stock.transfer.cancel_explain')}
                                                                confirmLabel={t('corr.stock.cancel')}
                                                                reasons={cancelReasons}
                                                                url={route('inventory.stock.transfers.cancel', transfer.id)}
                                                                destructive
                                                            />
                                                        )}
                                                    </div>
                                                    <ReceivePartDialog
                                                        transfer={transfer}
                                                        lossReasons={lossReasons}
                                                        correctionReasons={correctionReasons}
                                                    />
                                                    <BlockedNote availability={cancel} />
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                        {hasActiveFilters ? 'No transfers match this filter.' : 'No transfers yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-muted-foreground text-xs">Stock on the road belongs to neither warehouse until somebody marks it received.</p>
                    <Pagination meta={transfers} />
                </div>
            </div>
        </AppLayout>
    );
}
