import { Badge } from '@/components/ui/badge';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';

export interface UpcomingPayAdjustment {
    id: number;
    month: string | null;
    type: string;
    type_label: string;
    is_earning: boolean;
    /** Signed: minus for a deduction, plus for an addition. */
    amount: number;
    reason: string | null;
    decided_on: string | null;
    decided_by: string | null;
}

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * My deductions (Joy, 27 Oct 2026): the employee's own approved adjustments on
 * a month whose payroll is not finished yet, with the reason. Shared by My
 * payslips and My profile so both say the same thing.
 */
export function UpcomingPayAdjustments({ rows }: { rows: UpcomingPayAdjustment[] }) {
    const { t } = useTranslation();
    const typeLabel = (row: UpcomingPayAdjustment) => {
        const key = `my_adj.type.${row.type}` as TranslationKey;

        try {
            return t(key);
        } catch {
            return row.type_label;
        }
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <h3 className="mb-1 text-sm font-medium">{t('my_adj.heading')}</h3>
            <p className="text-muted-foreground mb-3 text-xs">{t('my_adj.hint')}</p>
            {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('my_adj.none')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">{t('my_adj.month')}</th>
                                <th className="py-2">{t('my_adj.type')}</th>
                                <th className="py-2 text-right">{t('my_adj.amount')}</th>
                                <th className="py-2">{t('my_adj.reason')}</th>
                                <th className="py-2">{t('my_adj.decided')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                    <td className="py-2 whitespace-nowrap">{row.month}</td>
                                    <td className="py-2">
                                        <Badge variant={row.is_earning ? 'secondary' : 'destructive'}>{typeLabel(row)}</Badge>
                                        <span className="text-muted-foreground block text-xs">
                                            {row.is_earning ? t('my_adj.added') : t('my_adj.deducted')}
                                        </span>
                                    </td>
                                    <td className={`py-2 text-right font-medium whitespace-nowrap ${row.is_earning ? '' : 'text-destructive'}`}>
                                        {row.amount < 0 ? '-' : '+'}
                                        {money(Math.abs(row.amount))}
                                    </td>
                                    <td className="py-2">{row.reason}</td>
                                    <td className="py-2 whitespace-nowrap">
                                        {showDate(row.decided_on)}
                                        {row.decided_by && (
                                            <span className="text-muted-foreground block text-xs">{t('my_adj.by', { name: row.decided_by })}</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
