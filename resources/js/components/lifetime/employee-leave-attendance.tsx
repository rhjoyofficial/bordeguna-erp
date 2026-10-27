import { Chip, CompactTable, Panel, Pill, type Tone } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

export interface EmployeeLeaveAttendanceData {
    leave_year: number;
    balances: { rows: { id: number; name: string; got: number; used: number; left: number }[]; url: string | null } | null;
    requests: {
        rows: {
            id: number;
            start_date: string;
            end_date: string;
            leave_type: string | null;
            days: number;
            paid_days: number;
            unpaid_days: number;
            status: string;
            pay_label: string;
            pay_decision: { reason: string | null; by: string | null; at: string | null } | null;
            cancel_reason: string | null;
        }[];
        url: string | null;
    } | null;
    attendance:
        | {
              year: number;
              month: number;
              label: string;
              start: string;
              end: string;
              running: boolean;
              present: number;
              late: number;
              half_day: number;
              absent: number;
              on_leave: number;
              missing_punch: number;
              provisional: number;
              made_unpaid: number;
              url: string | null;
          }[]
        | null;
    pay_changes: {
        rows: {
            id: number;
            period: string | null;
            type: string;
            type_label: string;
            is_earning: boolean;
            amount: string;
            reason: string | null;
            status: string;
            decided_on: string | null;
            cancel_reason: string | null;
        }[];
        url: string | null;
    } | null;
}

type Part = 'balances' | 'requests' | 'attendance' | 'pay';

const STATUS_TONE: Record<string, Tone> = {
    approved: 'ok',
    pending: 'warning',
    waiting: 'warning',
    rejected: 'danger',
    withdrawn: 'muted',
    cancelled: 'muted',
    generated: 'info',
};

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

function Table({ head, children, minWidth = '40rem' }: { head: ReactNode; children: ReactNode; minWidth?: string }) {
    return (
        <CompactTable minWidth={minWidth}>
            <thead>
                <tr className="text-muted-foreground">{head}</tr>
            </thead>
            <tbody>{children}</tbody>
        </CompactTable>
    );
}

/** A day count without a trailing ".0": 2, 1.5. */
const days = (value: number) => String(Math.round(value * 100) / 100);

/**
 * ⭐ 27 Oct 2026 (Joy: "from where hr can see an employee's all the things,
 * like leave, how much left, paid"): the employee page's leave and attendance
 * tab, next to Money. Leave left for a leave year, the leave requests with
 * their paid and unpaid days, attendance per 24-23 payroll month, and every
 * pay change. A part arrives null when the reader may not see it and is then
 * left out of the chips.
 */
export function EmployeeLeaveAttendancePanel({ employeeId, data }: { employeeId: number; data: EmployeeLeaveAttendanceData }) {
    const { t, tr } = useTranslation();
    const parts: { key: Part; label: string; show: boolean }[] = [
        { key: 'balances', label: t('life.la_balances'), show: data.balances !== null },
        { key: 'requests', label: t('life.la_requests'), show: data.requests !== null },
        { key: 'attendance', label: t('life.la_attendance'), show: data.attendance !== null },
        { key: 'pay', label: t('life.la_pay'), show: data.pay_changes !== null },
    ];
    const shown = parts.filter((entry) => entry.show);
    const [part, setPart] = useState<Part>(shown[0]?.key ?? 'balances');
    const words = (value: string) => tr(`life.word.${value}` as TranslationKey, value.replaceAll('_', ' '));

    if (shown.length === 0) {
        return null;
    }

    // Only this panel reloads; the money window and the rest of the URL stay.
    const toYear = (year: number) => {
        const query = Object.fromEntries(new URLSearchParams(window.location.search));
        router.get(
            `/hrm/employees/${employeeId}`,
            { ...query, leave_year: year },
            { preserveScroll: true, preserveState: true, only: ['leave_attendance'] },
        );
    };
    const status = (value: string) => <Pill tone={STATUS_TONE[value] ?? 'muted'}>{words(value)}</Pill>;
    const leaveTab = part === 'balances' || part === 'requests';
    const openUrl = leaveTab ? data.balances?.url : part === 'pay' ? data.pay_changes?.url : null;

    return (
        <Panel title={t('life.la_title')} flush>
            <div className="flex flex-col gap-3 px-4 pb-3">
                <p className="text-muted-foreground text-xs">{t('life.la_intro')}</p>
                {shown.length < parts.length && <p className="text-muted-foreground text-xs">{t('life.la_hidden')}</p>}
                <div className="flex flex-wrap items-center gap-1.5">
                    {shown.map((entry) => (
                        <Chip key={entry.key} active={part === entry.key} onClick={() => setPart(entry.key)}>
                            {entry.label}
                        </Chip>
                    ))}
                </div>
                {(leaveTab || openUrl) && (
                    <div className="flex flex-wrap items-center gap-2">
                        {leaveTab && (
                            <>
                                <span className="text-muted-foreground text-xs">{t('life.la_year')}</span>
                                <Button type="button" size="sm" variant="outline" onClick={() => toYear(data.leave_year - 1)}>
                                    {data.leave_year - 1}
                                </Button>
                                <span className="text-sm font-medium tabular-nums">{data.leave_year}</span>
                                <Button type="button" size="sm" variant="outline" onClick={() => toYear(data.leave_year + 1)}>
                                    {data.leave_year + 1}
                                </Button>
                            </>
                        )}
                        {openUrl && (
                            <Button type="button" size="sm" variant="ghost" asChild>
                                <Link href={openUrl}>{part === 'pay' ? t('life.la_open_inputs') : t('life.la_open_leave')}</Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {part === 'balances' && data.balances && (
                <Table
                    minWidth="24rem"
                    head={
                        <>
                            <th>{t('life.la_col_type')}</th>
                            <th className="text-right">{t('life.la_col_got')}</th>
                            <th className="text-right">{t('life.la_col_used')}</th>
                            <th className="text-right">{t('life.la_col_left')}</th>
                        </>
                    }
                >
                    {data.balances.rows.map((row) => (
                        <tr key={row.id}>
                            <td>{row.name}</td>
                            <td className="text-right tabular-nums">{days(row.got)}</td>
                            <td className="text-right tabular-nums">{days(row.used)}</td>
                            <td className="text-right font-medium tabular-nums">{days(row.left)}</td>
                        </tr>
                    ))}
                    {data.balances.rows.length === 0 && <Empty colSpan={4} />}
                </Table>
            )}

            {part === 'requests' && data.requests && (
                <Table
                    minWidth="52rem"
                    head={
                        <>
                            <th>{t('life.la_col_dates')}</th>
                            <th>{t('life.la_col_type')}</th>
                            <th className="text-right">{t('life.la_col_days')}</th>
                            <th className="text-right">{t('life.la_col_paid')}</th>
                            <th className="text-right">{t('life.la_col_unpaid')}</th>
                            <th>{t('life.col_status')}</th>
                            <th>{t('life.la_col_pay')}</th>
                        </>
                    }
                >
                    {data.requests.rows.map((row) => (
                        <tr key={row.id}>
                            <td className="whitespace-nowrap">
                                {showDate(row.start_date)}
                                {row.end_date !== row.start_date && <span className="text-muted-foreground"> - {showDate(row.end_date)}</span>}
                            </td>
                            <td>{row.leave_type ?? '-'}</td>
                            <td className="text-right tabular-nums">{days(row.days)}</td>
                            <td className="text-right tabular-nums">{days(row.paid_days)}</td>
                            <td className="text-right tabular-nums">{row.unpaid_days > 0 ? days(row.unpaid_days) : '-'}</td>
                            <td>
                                {status(row.status)}
                                {row.cancel_reason && <span className="text-muted-foreground block text-xs">{row.cancel_reason}</span>}
                            </td>
                            <td>
                                {row.pay_label}
                                {row.pay_decision && (
                                    <span className="text-muted-foreground block text-xs">
                                        {row.pay_decision.reason}
                                        {row.pay_decision.by && (
                                            <> ({t('life.la_decided_by', { name: row.pay_decision.by, date: row.pay_decision.at ?? '' })})</>
                                        )}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                    {data.requests.rows.length === 0 && <Empty colSpan={7} />}
                </Table>
            )}

            {part === 'attendance' && data.attendance && (
                <Table
                    minWidth="52rem"
                    head={
                        <>
                            <th>{t('life.la_col_cycle')}</th>
                            <th className="text-right">{t('life.la_col_present')}</th>
                            <th className="text-right">{t('life.la_col_late')}</th>
                            <th className="text-right">{t('life.la_col_half_day')}</th>
                            <th className="text-right">{t('life.la_col_absent')}</th>
                            <th className="text-right">{t('life.la_col_on_leave')}</th>
                            <th className="text-right">{t('life.la_col_missing')}</th>
                            <th className="text-right">{t('life.la_col_made_unpaid')}</th>
                        </>
                    }
                >
                    {data.attendance.map((row) => (
                        <tr key={`${row.year}-${row.month}`}>
                            <td>
                                {row.url ? (
                                    <Link href={row.url} className="underline-offset-2 hover:underline">
                                        {row.label}
                                    </Link>
                                ) : (
                                    row.label
                                )}
                                {(row.running || row.provisional > 0) && (
                                    <span className="text-muted-foreground block text-xs">
                                        {[
                                            row.running ? t('life.la_running') : null,
                                            row.provisional > 0 ? t('life.la_provisional', { count: row.provisional }) : null,
                                        ]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </span>
                                )}
                            </td>
                            <td className="text-right tabular-nums">{row.present}</td>
                            <td className="text-right tabular-nums">{row.late}</td>
                            <td className="text-right tabular-nums">{row.half_day}</td>
                            <td className="text-right tabular-nums">{row.absent}</td>
                            <td className="text-right tabular-nums">{row.on_leave}</td>
                            <td className="text-right tabular-nums">{row.missing_punch}</td>
                            <td className="text-right tabular-nums">{row.made_unpaid > 0 ? days(row.made_unpaid) : '-'}</td>
                        </tr>
                    ))}
                </Table>
            )}

            {part === 'pay' && data.pay_changes && (
                <Table
                    minWidth="48rem"
                    head={
                        <>
                            <th>{t('life.col_month')}</th>
                            <th>{t('life.la_col_type')}</th>
                            <th className="text-right">{t('life.col_amount')}</th>
                            <th>{t('life.col_reason')}</th>
                            <th>{t('life.col_status')}</th>
                            <th>{t('life.la_col_decided')}</th>
                        </>
                    }
                >
                    {data.pay_changes.rows.map((row) => (
                        <tr key={row.id}>
                            <td className="whitespace-nowrap">{row.period ?? '-'}</td>
                            <td className="whitespace-nowrap">{row.type_label}</td>
                            <td className="text-right whitespace-nowrap tabular-nums">
                                {row.is_earning ? '' : '-'}
                                {formatMoney(row.amount)}
                            </td>
                            <td>{row.reason}</td>
                            <td>
                                {status(row.status)}
                                {row.cancel_reason && <span className="text-muted-foreground block text-xs">{row.cancel_reason}</span>}
                            </td>
                            <td className="text-muted-foreground whitespace-nowrap">{row.decided_on ?? '-'}</td>
                        </tr>
                    ))}
                    {data.pay_changes.rows.length === 0 && <Empty colSpan={6} />}
                </Table>
            )}
        </Panel>
    );
}
