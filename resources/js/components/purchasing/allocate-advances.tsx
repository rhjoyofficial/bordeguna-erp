import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useEffect, useState } from 'react';

export type AdvanceRow = { key: string; amount: string };

type OpenAdvance = { key: string; number: string; date: string | null; open: string; po_number: string | null };

const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

/** Only the rows the server should see. */
export const filledAdvanceRows = (rows: AdvanceRow[]): AdvanceRow[] => rows.filter((row) => toPaisa(row.amount) > 0);

export const advancePaisa = (rows: AdvanceRow[]): number => filledAdvanceRows(rows).reduce((sum, row) => sum + toPaisa(row.amount), 0);

/**
 * ⭐ Initial plan B1 (R56, 25 Oct 2026): "Allocate advance: pick an earlier
 * advance to this supplier and deduct it from this bill", on the one-screen
 * purchase and Buy and receive. The supplier's open advances come from the
 * server (the bill screen's own list, oldest first); the server applies them
 * with the bill screen's rules once the bill is saved, before any payment.
 */
export function AllocateAdvances({
    supplierId,
    rows,
    onChange,
    billPaisa,
    errors = {},
}: {
    supplierId: string;
    rows: AdvanceRow[];
    onChange: (rows: AdvanceRow[]) => void;
    /** What the bill will owe, so "Use" never takes more than that. */
    billPaisa: number;
    errors?: Record<string, string | undefined>;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<OpenAdvance[]>([]);

    useEffect(() => {
        let cancelled = false;
        setOpen([]);
        onChange([]);

        if (supplierId === '') {
            return;
        }

        fetch(`/purchasing/suppliers/${supplierId}/open-advances`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : []))
            .then((list: OpenAdvance[]) => {
                if (!cancelled) {
                    setOpen(list);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
        // Only a new supplier starts the list again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplierId]);

    if (open.length === 0) {
        return null;
    }

    const amountOf = (key: string) => rows.find((row) => row.key === key)?.amount ?? '';
    const setAmount = (key: string, amount: string) =>
        onChange([...rows.filter((row) => row.key !== key), { key, amount }].filter((row) => row.amount !== ''));
    const used = advancePaisa(rows);

    const takeAll = (advance: OpenAdvance) => {
        const others = used - toPaisa(amountOf(advance.key));
        const take = Math.max(0, Math.min(toPaisa(advance.open), billPaisa - others));
        setAmount(advance.key, take > 0 ? (take / 100).toFixed(2) : '');
    };

    return (
        <section className="flex flex-col gap-2 rounded-lg border p-3">
            <h3 className="text-sm font-medium">{t('ipp.adv.title')}</h3>
            <p className="text-muted-foreground text-xs">{t('ipp.adv.hint')}</p>
            <ul className="grid gap-1.5">
                {open.map((advance) => (
                    <li key={advance.key} className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="w-44">
                            {advance.number}
                            {advance.po_number ? ` (${advance.po_number})` : ''}
                        </span>
                        <span className="text-muted-foreground w-24 text-xs">{showDate(advance.date)}</span>
                        <span className="w-32 text-right tabular-nums">{t('ipp.adv.open', { amount: formatMoney(advance.open) })}</span>
                        <Input
                            inputMode="decimal"
                            aria-label={t('ipp.adv.take', { number: advance.number })}
                            className="w-32 text-right tabular-nums"
                            value={amountOf(advance.key)}
                            onChange={(e) => setAmount(advance.key, e.target.value)}
                        />
                        <Button type="button" variant="ghost" size="sm" onClick={() => takeAll(advance)}>
                            {t('ipp.adv.use')}
                        </Button>
                    </li>
                ))}
            </ul>
            {used > 0 && <p className="text-sm">{t('ipp.adv.total', { amount: formatMoney(used / 100) })}</p>}
            {used > billPaisa && billPaisa > 0 && <p className="text-destructive text-sm">{t('ipp.adv.too_much')}</p>}
            <InputError message={errors.advances} />
        </section>
    );
}
