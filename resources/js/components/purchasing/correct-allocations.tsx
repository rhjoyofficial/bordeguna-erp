import { Input } from '@/components/ui/input';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';

export type AllocationRow = { id: number; bill_number: string; bill_date: string | null; open: string; amount: string };

/**
 * Corrections wave 1 (20 Oct 2026): which bills a corrected payment or credit
 * note settles, and how much of each. "Open" already counts what the record
 * being corrected settled, because that comes back when it is reversed.
 */
export function CorrectAllocations({
    title,
    rows,
    onChange,
}: {
    title: string;
    rows: AllocationRow[];
    onChange: (index: number, amount: string) => void;
}) {
    const { t } = useTranslation();
    const total = rows.reduce((sum, row) => sum + (Number.parseFloat(row.amount) || 0), 0);

    return (
        <div className="overflow-x-auto rounded-md border">
            <div className="bg-muted/50 border-b p-3 text-sm font-semibold">{title}</div>
            <table className="w-full text-sm">
                <thead className="text-muted-foreground text-left">
                    <tr>
                        <th className="p-3 font-medium">{t('corr.sup.bill')}</th>
                        <th className="p-3 text-right font-medium">{t('corr.sup.open')}</th>
                        <th className="p-3 text-right font-medium">{t('corr.sup.amount')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={row.id} className="border-t">
                            <td className="p-3">
                                <div className="font-medium">{row.bill_number}</div>
                                <div className="text-muted-foreground text-xs">{showDate(row.bill_date)}</div>
                            </td>
                            <td className="p-3 text-right tabular-nums">{formatMoney(row.open)}</td>
                            <td className="p-3 text-right">
                                <Input
                                    aria-label={`${t('corr.sup.amount')} ${row.bill_number}`}
                                    className="ml-auto w-32 text-right"
                                    inputMode="decimal"
                                    value={row.amount}
                                    onChange={(e) => onChange(index, e.target.value)}
                                />
                            </td>
                        </tr>
                    ))}
                    <tr className="border-t font-semibold">
                        <td className="p-3" colSpan={2}>
                            {t('corr.sup.new_total')}
                        </td>
                        <td className="p-3 text-right tabular-nums">BDT {formatMoney(total.toFixed(2))}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
