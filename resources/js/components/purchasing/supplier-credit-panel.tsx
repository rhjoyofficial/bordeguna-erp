import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useEffect, useState } from 'react';

/**
 * ⭐ Ruling P6: the supplier credit WARNING, each part apart, as
 * App\Modules\Purchasing\Support\SupplierCredit sends it.
 *
 * ⚠ Billed and received-not-billed are in the books; open orders are a
 * commitment, not a debt, and are labelled so; advances are shown and never
 * taken off. Nothing here blocks a save.
 */
export interface SupplierCreditView {
    credit_limit: string | null;
    billed: string;
    received_not_billed: string;
    open_orders: string;
    advances: string;
    exposure: string;
    adding: string;
    after: string;
    after_less_advances: string;
    headroom: string | null;
    level: 'none' | 'ok' | 'near' | 'over';
    near_percent: number;
    terms: { type: string; days: number | null; label: string } | null;
}

const TONE: Record<SupplierCreditView['level'], string> = {
    none: 'border-border',
    ok: 'border-border',
    near: 'border-amber-500/60 bg-amber-500/10',
    over: 'border-destructive/50 bg-destructive/10',
};

export function SupplierCreditPanel({ credit, context = 'none' }: { credit: SupplierCreditView; context?: 'order' | 'bill' | 'none' }) {
    const { t } = useTranslation();
    const adding = Number(credit.adding) > 0;
    const headroom = credit.headroom === null ? null : formatMoney(String(Math.abs(Number(credit.headroom))));

    const rows: Array<[string, string]> = [
        [t('credit.billed'), credit.billed],
        [t('credit.unbilled'), credit.received_not_billed],
        [t('credit.open_orders'), credit.open_orders],
        ...(adding ? ([[t('credit.this_order'), credit.adding]] as Array<[string, string]>) : []),
    ];

    return (
        <section
            className={`flex w-fit max-w-full flex-col gap-2 rounded-lg border p-3 text-sm ${TONE[credit.level]}`}
            data-credit-level={credit.level}
            aria-live="polite"
        >
            <h3 className="font-semibold">{t('credit.title')}</h3>
            <dl className="grid grid-cols-[auto_auto] gap-x-6 gap-y-1">
                {rows.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="text-right tabular-nums">{formatMoney(value)}</dd>
                    </div>
                ))}
                <dt className="font-medium">{adding ? t('credit.after') : t('credit.total')}</dt>
                <dd className="text-right font-medium tabular-nums">{formatMoney(credit.after)}</dd>
                {credit.credit_limit !== null && (
                    <>
                        <dt className="text-muted-foreground">{t('credit.limit')}</dt>
                        <dd className="text-right tabular-nums">{formatMoney(credit.credit_limit)}</dd>
                    </>
                )}
                <dt className="text-muted-foreground">{t('credit.advances')}</dt>
                <dd className="text-right tabular-nums">{formatMoney(credit.advances)}</dd>
            </dl>
            <p
                className={
                    credit.level === 'over' ? 'text-destructive font-medium' : credit.level === 'near' ? 'font-medium' : 'text-muted-foreground'
                }
            >
                {credit.level === 'none' && t('credit.none')}
                {credit.level === 'ok' && headroom !== null && t('credit.ok', { amount: headroom })}
                {credit.level === 'near' && headroom !== null && t('credit.near', { amount: headroom })}
                {credit.level === 'over' && headroom !== null && t('credit.over', { amount: headroom })}
            </p>
            {Number(credit.advances) > 0 && <p className="text-muted-foreground text-xs">{t('credit.advance_note')}</p>}
            {context === 'bill' && <p className="text-muted-foreground text-xs">{t('credit.bill_note')}</p>}
        </section>
    );
}

/**
 * The warning for a supplier picked on a form, with `addingPaisa` (the order
 * being typed) added to the open orders. Asked again, after a short pause, as
 * the supplier or the total changes; null until there is a supplier.
 */
export function useSupplierCredit(supplierId: number | null, addingPaisa: number): SupplierCreditView | null {
    const [credit, setCredit] = useState<SupplierCreditView | null>(null);

    useEffect(() => {
        if (supplierId === null) {
            setCredit(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams({ adding: (Math.max(0, addingPaisa) / 100).toFixed(2) });

            fetch(`/purchasing/suppliers/${supplierId}/credit?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((view: SupplierCreditView | null) => setCredit(view))
                .catch(() => undefined);
        }, 400);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [supplierId, addingPaisa]);

    return credit;
}
