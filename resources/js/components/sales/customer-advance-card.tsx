import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export type AdvanceRow = { key: string; advance_id: number | null; number: string; date: string; open: string };

export type CustomerAdvances = {
    with_us: string;
    paid: string;
    applied: string;
    refunded: string;
    rows: AdvanceRow[];
    url: string;
};

/**
 * ⭐ B11 (24 Oct 2026): the customer page's "their advance with us": what is
 * still open, what was paid, used on invoices and paid back, each open advance
 * oldest first, and the way to the Advances page.
 */
export function CustomerAdvanceCard({ advances }: { advances: CustomerAdvances }) {
    const { t } = useTranslation();

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-muted-foreground text-xs">{t('adv.with_us')}</p>
                    <p className="mt-1 text-xl font-semibold tabular-nums">{advances.with_us}</p>
                    <p className="text-muted-foreground text-xs">{t('adv.with_us_hint')}</p>
                </div>
                <Link href={advances.url} className="text-sm underline-offset-2 hover:underline">
                    {t('adv.page_link')}
                </Link>
            </div>
            <dl className="mt-3 grid grid-cols-3 gap-3 text-sm">
                <div>
                    <dt className="text-muted-foreground text-xs">{t('adv.paid_total')}</dt>
                    <dd className="tabular-nums">{advances.paid}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">{t('adv.applied_total')}</dt>
                    <dd className="tabular-nums">{advances.applied}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">{t('adv.refunded_total')}</dt>
                    <dd className="tabular-nums">{advances.refunded}</dd>
                </div>
            </dl>
            {advances.rows.length > 0 && (
                <ul className="mt-3 space-y-1 border-t pt-3 text-sm">
                    {advances.rows.map((row) => (
                        <li key={row.key} className="flex justify-between gap-3">
                            <span>
                                {row.advance_id === null ? t('adv.on_account') : row.number}{' '}
                                <span className="text-muted-foreground text-xs">{showDate(row.date)}</span>
                            </span>
                            <span className="tabular-nums">{formatMoney(row.open)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
