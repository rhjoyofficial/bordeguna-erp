import { Chip, CompactTable, Panel } from '@/components/inventory/inventory-ui';
import { type LifetimeRange, LifetimeRangeBar } from '@/components/lifetime/lifetime-ui';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

export interface EmployeeMoneyData {
    range: LifetimeRange;
    salary: {
        rows: {
            id: number;
            year: number | null;
            month: number | null;
            status: string | null;
            earnings: string;
            deductions: string;
            net: string;
            paid: string;
            url: string | null;
        }[];
        net: string;
        paid: string;
    } | null;
    loans:
        | {
              id: number;
              reference: string | null;
              type: string | null;
              status: string | null;
              disbursed_on: string | null;
              principal: string;
              repaid: string;
              outstanding: string;
          }[]
        | null;
    claims: { id: number; number: string | null; date: string | null; purpose: string | null; amount: string; state: string; settled: boolean }[];
    purchases: {
        id: number;
        number: string | null;
        date: string | null;
        status: string;
        payment_mode: string | null;
        invoice: string | null;
        amount: string | null;
    }[];
    set_offs: { id: number; date: string | null; reference: string | null; amount: string; reversed: boolean }[];
    ledger: { code: string; name: string; opening: string; debits: string; credits: string; closing: string; lines: number }[] | null;
    /** ⚠ SEAM: always null on this branch (no employee cash accounts yet). */
    cash_account: Record<string, unknown> | null;
}

type Part = 'salary' | 'loans' | 'claims' | 'purchases' | 'set_offs' | 'ledger' | 'cash';

function Empty({ colSpan }: { colSpan: number }) {
    const { t } = useTranslation();

    return (
        <tr>
            <td colSpan={colSpan} className="text-muted-foreground p-4 text-center">
                {t('life.none')}
            </td>
        </tr>
    );
}

function Table({ head, children }: { head: ReactNode; children: ReactNode }) {
    return (
        <CompactTable minWidth="40rem">
            <thead>
                <tr className="text-muted-foreground">{head}</tr>
            </thead>
            <tbody>{children}</tbody>
        </CompactTable>
    );
}

/**
 * ⭐ B23 lifetime views (25 Oct 2026; R77): the employee page's money tab.
 * Each part arrives null from the server when the reader may not see it, and
 * is then left out of the chips (never shown as an empty "never paid").
 */
export function EmployeeMoneyPanel({ employeeId, money }: { employeeId: number; money: EmployeeMoneyData }) {
    const { t, tr } = useTranslation();
    const base = `/hrm/employees/${employeeId}`;
    const parts: { key: Part; label: string; show: boolean }[] = [
        { key: 'salary', label: t('life.emp_salary'), show: money.salary !== null },
        { key: 'loans', label: t('life.emp_loans'), show: money.loans !== null },
        { key: 'claims', label: t('life.emp_claims'), show: true },
        { key: 'purchases', label: t('life.emp_purchases'), show: true },
        { key: 'set_offs', label: t('life.emp_set_offs'), show: true },
        { key: 'ledger', label: t('life.emp_ledger'), show: money.ledger !== null },
        { key: 'cash', label: t('life.emp_cash'), show: true },
    ];
    const [part, setPart] = useState<Part>(parts.find((entry) => entry.show)?.key ?? 'claims');
    const words = (value: string | null) => (value ? tr(`life.word.${value}` as TranslationKey, value.replaceAll('_', ' ')) : '');

    return (
        <Panel title={t('life.emp_title')} flush>
            <div className="flex flex-col gap-3 px-4 pb-3">
                <p className="text-muted-foreground text-xs">{t('life.emp_intro')}</p>
                <LifetimeRangeBar range={money.range} baseUrl={base} exportUrl={`${base}/money-export`} />
                {(money.salary === null || money.ledger === null) && <p className="text-muted-foreground text-xs">{t('life.emp_hidden')}</p>}
                <div className="flex flex-wrap gap-1.5">
                    {parts
                        .filter((entry) => entry.show)
                        .map((entry) => (
                            <Chip key={entry.key} active={part === entry.key} onClick={() => setPart(entry.key)}>
                                {entry.label}
                            </Chip>
                        ))}
                </div>
            </div>

            {part === 'salary' && money.salary && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_month')}</th>
                            <th>{t('life.col_status')}</th>
                            <th className="text-right">{t('life.col_earnings')}</th>
                            <th className="text-right">{t('life.col_deductions')}</th>
                            <th className="text-right">{t('life.col_net_pay')}</th>
                            <th className="text-right">{t('life.col_paid')}</th>
                        </>
                    }
                >
                    {money.salary.rows.map((row) => (
                        <tr key={row.id}>
                            <td className="whitespace-nowrap">
                                {row.url ? (
                                    <Link href={row.url} className="underline-offset-2 hover:underline">
                                        {row.month}/{row.year}
                                    </Link>
                                ) : (
                                    `${row.month}/${row.year}`
                                )}
                            </td>
                            <td className="text-muted-foreground">{words(row.status)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.earnings)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.deductions)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.net)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.paid)}</td>
                        </tr>
                    ))}
                    {money.salary.rows.length === 0 ? (
                        <Empty colSpan={6} />
                    ) : (
                        <tr className="font-medium">
                            <td colSpan={4}>{t('life.total')}</td>
                            <td className="text-right tabular-nums">{formatMoney(money.salary.net)}</td>
                            <td className="text-right tabular-nums">{formatMoney(money.salary.paid)}</td>
                        </tr>
                    )}
                </Table>
            )}

            {part === 'loans' && money.loans && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_date')}</th>
                            <th>{t('life.col_number')}</th>
                            <th>{t('life.col_status')}</th>
                            <th className="text-right">{t('life.col_principal')}</th>
                            <th className="text-right">{t('life.col_repaid')}</th>
                            <th className="text-right">{t('life.col_owed')}</th>
                        </>
                    }
                >
                    {money.loans.map((row) => (
                        <tr key={row.id}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.disbursed_on)}</td>
                            <td>
                                {row.reference}
                                <span className="text-muted-foreground block text-xs">{words(row.type)}</span>
                            </td>
                            <td className="text-muted-foreground">{words(row.status)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.principal)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.repaid)}</td>
                            <td className="text-right font-medium tabular-nums">{formatMoney(row.outstanding)}</td>
                        </tr>
                    ))}
                    {money.loans.length === 0 && <Empty colSpan={6} />}
                </Table>
            )}

            {part === 'claims' && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_date')}</th>
                            <th>{t('life.col_number')}</th>
                            <th>{t('life.col_purpose')}</th>
                            <th>{t('life.col_status')}</th>
                            <th className="text-right">{t('life.col_amount')}</th>
                        </>
                    }
                >
                    {money.claims.map((row) => (
                        <tr key={row.id}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                            <td className="whitespace-nowrap">{row.number ?? `#${row.id}`}</td>
                            <td>{row.purpose}</td>
                            <td className="text-muted-foreground">{row.state}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.amount)}</td>
                        </tr>
                    ))}
                    {money.claims.length === 0 && <Empty colSpan={5} />}
                </Table>
            )}

            {part === 'purchases' && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_date')}</th>
                            <th>{t('life.col_number')}</th>
                            <th>{t('life.col_status')}</th>
                            <th>{t('life.col_invoice')}</th>
                            <th className="text-right">{t('life.col_amount')}</th>
                        </>
                    }
                >
                    {money.purchases.map((row) => (
                        <tr key={row.id}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                            <td className="whitespace-nowrap">{row.number ?? `#${row.id}`}</td>
                            <td className="text-muted-foreground">
                                {words(row.status)}
                                {row.payment_mode && <span className="block text-xs">{words(row.payment_mode)}</span>}
                            </td>
                            <td className="whitespace-nowrap">{row.invoice ?? '-'}</td>
                            <td className="text-right tabular-nums">{row.amount ? formatMoney(row.amount) : '-'}</td>
                        </tr>
                    ))}
                    {money.purchases.length === 0 && <Empty colSpan={5} />}
                </Table>
            )}

            {part === 'set_offs' && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_date')}</th>
                            <th>{t('life.col_reference')}</th>
                            <th className="text-right">{t('life.col_amount')}</th>
                        </>
                    }
                >
                    {money.set_offs.map((row) => (
                        <tr key={row.id}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                            <td>
                                {row.reference ?? `#${row.id}`}
                                {row.reversed && <span className="text-muted-foreground ml-1 text-xs">({t('life.reversed')})</span>}
                            </td>
                            <td className="text-right tabular-nums">{formatMoney(row.amount)}</td>
                        </tr>
                    ))}
                    {money.set_offs.length === 0 && <Empty colSpan={3} />}
                </Table>
            )}

            {part === 'ledger' && money.ledger && (
                <Table
                    head={
                        <>
                            <th>{t('life.col_head')}</th>
                            <th className="text-right">{t('life.col_opening')}</th>
                            <th className="text-right">{t('life.col_debits')}</th>
                            <th className="text-right">{t('life.col_credits')}</th>
                            <th className="text-right">{t('life.col_closing')}</th>
                        </>
                    }
                >
                    {money.ledger.map((row) => (
                        <tr key={row.code}>
                            <td>
                                <span className="text-muted-foreground font-mono text-xs">{row.code}</span> {row.name}
                            </td>
                            <td className="text-right tabular-nums">{formatMoney(row.opening)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.debits)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.credits)}</td>
                            <td className="text-right font-medium tabular-nums">{formatMoney(row.closing)}</td>
                        </tr>
                    ))}
                    {money.ledger.length === 0 && <Empty colSpan={5} />}
                </Table>
            )}

            {part === 'cash' && <p className="text-muted-foreground px-4 pb-4 text-sm">{t('life.emp_cash_none')}</p>}
        </Panel>
    );
}
