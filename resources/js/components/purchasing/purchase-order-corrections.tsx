import { BlockedAction, CorrectionReasonDialog, type ActionSetProps, type ReasonOption } from '@/components/purchasing/correction-actions';
import { RecordHistory } from '@/components/record-history';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): a purchase order's buttons, by stage,
 * from PurchaseOrderActionPolicy: Withdraw (waiting), Edit (draft or approved,
 * nothing received), Cancel, Fix details (after receipt), plus Copy and the
 * History. A blocked one stays visible, greyed, with its sentence.
 */
export type PurchaseOrderCorrection = {
    actions: ActionSetProps;
    reasons: Record<string, ReasonOption[]>;
    details_fixed_at: string | null;
    suppliers: { id: number; name: string }[];
    can_copy: boolean;
};

export function PurchaseOrderCorrections({
    order,
    correction,
}: {
    order: { id: number; po_number: string; order_date: string | null; expected_date: string | null; note: string | null; supplier_id: number };
    correction: PurchaseOrderCorrection;
}) {
    const { t } = useTranslation();
    const [orderDate, setOrderDate] = useState(order.order_date ?? '');
    const [expectedDate, setExpectedDate] = useState(order.expected_date ?? '');
    const [note, setNote] = useState(order.note ?? '');
    const [supplierId, setSupplierId] = useState('');

    const buttons = correction.actions.actions.map((availability) => {
        const label =
            {
                withdraw: t('corr.po.withdraw'),
                edit: t('corr.po.edit'),
                cancel: t('corr.po.cancel'),
                fix_details: t('corr.po.fix_details'),
            }[availability.action] ?? availability.label;

        if (!availability.allowed) {
            return <BlockedAction key={availability.action} availability={availability} label={label} />;
        }

        switch (availability.action) {
            case 'edit':
                return (
                    <Button key="edit" variant="outline" size="sm" asChild>
                        <Link href={`/purchasing/purchase-orders/${order.id}/edit`}>{label}</Link>
                    </Button>
                );
            case 'withdraw':
                return (
                    <CorrectionReasonDialog
                        key="withdraw"
                        trigger={
                            <Button size="sm" variant="outline">
                                {label}
                            </Button>
                        }
                        title={t('corr.po.withdraw_title', { number: order.po_number })}
                        description={t('corr.po.withdraw_text')}
                        reasons={correction.reasons.withdrawal ?? []}
                        submitUrl={`/purchasing/purchase-orders/${order.id}/withdraw`}
                        confirmLabel={label}
                    />
                );
            case 'cancel':
                return (
                    <CorrectionReasonDialog
                        key="cancel"
                        trigger={
                            <Button size="sm" variant="outline">
                                {label}
                            </Button>
                        }
                        title={t('corr.po.cancel_title', { number: order.po_number })}
                        description={t('corr.po.cancel_text')}
                        reasons={correction.reasons.cancellation ?? []}
                        submitUrl={`/purchasing/purchase-orders/${order.id}/cancel`}
                        confirmLabel={label}
                        destructive
                    />
                );
            case 'fix_details':
                return (
                    <CorrectionReasonDialog
                        key="fix_details"
                        trigger={
                            <Button size="sm" variant="outline">
                                {label}
                            </Button>
                        }
                        title={t('corr.po.fix_title', { number: order.po_number })}
                        description={t('corr.po.fix_text')}
                        reasons={correction.reasons.fix_details ?? []}
                        submitUrl={`/purchasing/purchase-orders/${order.id}/fix-details`}
                        confirmLabel={t('corr.po.save_fix')}
                        data={() => ({
                            order_date: orderDate,
                            expected_date: expectedDate === '' ? null : expectedDate,
                            note,
                            supplier_id: supplierId === '' ? null : Number(supplierId),
                        })}
                    >
                        {/* Dates first (Joy, 19 Oct 2026). */}
                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1">
                                <Label htmlFor="fix-order-date" required>
                                    {t('corr.po.order_date')}
                                </Label>
                                <Input
                                    id="fix-order-date"
                                    type="date"
                                    className="w-44"
                                    value={orderDate}
                                    onChange={(e) => setOrderDate(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="fix-expected-date">{t('corr.po.expected_date')}</Label>
                                <Input
                                    id="fix-expected-date"
                                    type="date"
                                    className="w-44"
                                    value={expectedDate}
                                    onChange={(e) => setExpectedDate(e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="fix-note">{t('corr.po.note')}</Label>
                            <Input id="fix-note" value={note} onChange={(e) => setNote(e.target.value)} />
                        </div>
                        {correction.suppliers.length > 0 && (
                            <div className="grid gap-1">
                                <Label htmlFor="fix-supplier">{t('corr.po.supplier')}</Label>
                                <select
                                    id="fix-supplier"
                                    value={supplierId}
                                    onChange={(e) => setSupplierId(e.target.value)}
                                    className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                                >
                                    <option value="">{t('corr.po.supplier_same')}</option>
                                    {correction.suppliers
                                        .filter((supplier) => supplier.id !== order.supplier_id)
                                        .map((supplier) => (
                                            <option key={supplier.id} value={supplier.id}>
                                                {supplier.name}
                                            </option>
                                        ))}
                                </select>
                            </div>
                        )}
                    </CorrectionReasonDialog>
                );
            default:
                return null;
        }
    });

    return (
        <div className="flex flex-wrap items-start gap-2">
            {buttons}
            {correction.can_copy && (
                <Button variant="outline" size="sm" asChild>
                    <Link href={`/purchasing/purchase-orders/new?copy=${order.id}`}>{t('corr.po.copy')}</Link>
                </Button>
            )}
            <span className="self-center">
                <RecordHistory type="purchasing.purchase_order" id={order.id} label={order.po_number} />
            </span>
        </div>
    );
}
