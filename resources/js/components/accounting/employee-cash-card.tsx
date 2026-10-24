import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

export type EmployeeCashAccount = {
    id: number;
    name: string;
    is_active: boolean;
    balance: string;
    position?: 'holds' | 'owed' | 'even';
    position_amount?: string;
    lines: { journal_id: number; date: string; memo: string | null; amount: string; is_in: boolean; url: string }[];
};

/** "holds Tk 4,000.00" / "company owes Tk 10,000.00" for an Employee cash account. */
export function EmployeeCashPosition({ position, amount }: { position?: string; amount?: string }) {
    const { t } = useTranslation();

    if (!position || amount === undefined) {
        return null;
    }

    return (
        <span className={position === 'owed' ? 'text-destructive font-medium' : 'font-medium'}>
            {position === 'holds' ? t('ecash.holds', { amount }) : position === 'owed' ? t('ecash.owed', { amount }) : t('ecash.even')}
        </span>
    );
}

/**
 * ⭐ Initial plan B3 (R63, 25 Oct 2026): "His petty cash ledger shows at any
 * time how much company cash he holds, or how much the company owes him", on
 * the employee page. Read from the ledger, never stored.
 */
export function EmployeeCashCard({ accounts }: { accounts: EmployeeCashAccount[] }) {
    const { t } = useTranslation();

    if (accounts.length === 0) {
        return null;
    }

    return (
        <section className="border-border bg-card rounded-lg border p-4">
            <h2 className="mb-1 text-sm font-semibold">{t('ecash.title')}</h2>
            <p className="text-muted-foreground mb-3 text-xs">{t('ecash.hint')}</p>
            <div className="flex flex-col gap-4">
                {accounts.map((account) => (
                    <div key={account.id} className="flex flex-col gap-2">
                        <p className="text-sm">
                            <span className="mr-3">{account.name}</span>
                            <EmployeeCashPosition position={account.position} amount={account.position_amount} />
                        </p>
                        {account.lines.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('ecash.no_lines')}</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="text-sm">
                                    <tbody>
                                        {account.lines.map((line) => (
                                            <tr key={`${line.journal_id}-${line.date}-${line.amount}`} className="border-b last:border-0">
                                                <td className="text-muted-foreground py-1 pr-4">{showDate(line.date)}</td>
                                                <td className="py-1 pr-4">
                                                    <Link href={line.url} className="hover:underline">
                                                        {line.memo ?? '-'}
                                                    </Link>
                                                </td>
                                                <td className={`py-1 text-right tabular-nums ${line.is_in ? '' : 'text-destructive'}`}>
                                                    {line.amount}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}
