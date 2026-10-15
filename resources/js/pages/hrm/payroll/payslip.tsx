import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Line {
    code: string;
    label: string;
    amount: number;
    quantity: number | null;
    rate: number | null;
    formula: string | null;
    source: string | null;
}

interface PaymentEvent {
    type: string;
    amount: number;
    method: string;
    date: string;
    reference: string | null;
    failure_reason: string | null;
}

interface Doc {
    employee: Record<string, string | null>;
    period: { label: string | null; start: string | null; end: string | null; divisor_days: number | null; run_version: number | null };
    attendance: {
        present_days: number;
        absent_days: number;
        paid_leave_days: number;
        unpaid_leave_days: number;
        late_occurrences: number;
        deductible_days: number;
    } | null;
    earnings: Line[];
    deductions: Line[];
    totals: { gross_salary: number; total_earnings: number; total_deductions: number; net: number };
    payment: { method: string; status: string; paid_total: number; events: PaymentEvent[] };
    notes: string[];
    block_reasons: string[];
    generated: { by: string | null; at: string };
}

interface Props {
    payslip_id: number;
    run_status: string | null;
    can_manage: boolean;
    password_hint: string;
    password_unavailable: string | null;
    document: Doc;
}

const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function LineTable({ title, lines, total }: { title: string; lines: Line[]; total: number }) {
    return (
        <div className={cardClass}>
            <h3 className="mb-3 font-medium">{title}</h3>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className={headClass}>
                            <th className="py-2">Component</th>
                            <th className="py-2">How it was worked out</th>
                            <th className="py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={3} className="text-muted-foreground py-3">
                                    Nothing recorded.
                                </td>
                            </tr>
                        )}
                        {lines.map((line) => (
                            <tr key={`${line.code}-${line.source ?? ''}`} className={rowClass}>
                                <td className="py-2">{line.label}</td>
                                <td className="text-muted-foreground py-2 text-xs">{line.formula ?? '-'}</td>
                                <td className="py-2 text-right whitespace-nowrap">{money(line.amount)}</td>
                            </tr>
                        ))}
                        <tr>
                            <td className="py-2 font-medium" colSpan={2}>
                                Total {title.toLowerCase()}
                            </td>
                            <td className="py-2 text-right font-medium">{money(total)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function PayslipPage({ payslip_id, can_manage, password_hint, password_unavailable, document: doc }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Payroll', href: '/hrm/payroll' },
        { title: `Payslip - ${doc.period.label ?? ''}`, href: `/hrm/payslips/${payslip_id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payslip - ${doc.period.label ?? ''}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">
                            {doc.employee.name} <span className="text-muted-foreground">{doc.employee.code}</span>
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {doc.period.label} ({doc.period.start} – {doc.period.end})
                            {(doc.period.run_version ?? 1) > 1 ? `, revision ${doc.period.run_version}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{doc.payment.status}</Badge>
                        {password_unavailable === null ? (
                            <a href={route('hrm.payslips.pdf', payslip_id)}>
                                <Button size="sm">Download PDF</Button>
                            </a>
                        ) : (
                            <Button size="sm" disabled>
                                PDF unavailable
                            </Button>
                        )}
                        {can_manage && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(route('hrm.payslips.email', payslip_id), {}, { preserveScroll: true })}
                            >
                                Email to employee
                            </Button>
                        )}
                    </div>
                </div>

                {password_unavailable ? (
                    <div className={cardClass}>
                        <p className="text-destructive text-sm">{password_unavailable}</p>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        The PDF is watermarked, stamped with who produced it, and password protected. Password: {password_hint}
                    </p>
                )}

                {doc.block_reasons.length > 0 && (
                    <div className={cardClass}>
                        <h3 className="text-destructive mb-2 font-medium">Held</h3>
                        <ul className="text-muted-foreground list-inside list-disc text-sm">
                            {doc.block_reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className={cardClass}>
                    <h3 className="mb-3 font-medium">Employee</h3>
                    <div className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            ['Designation', doc.employee.designation],
                            ['Department', doc.employee.department],
                            ['Section', doc.employee.section],
                            ['Office', doc.employee.office],
                            ['Joined', doc.employee.joining_date],
                            ['Employment', doc.employee.employment_type],
                            ['Payment method', doc.payment.method],
                            ['Paid so far', money(doc.payment.paid_total)],
                        ].map(([label, value]) => (
                            <div key={String(label)}>
                                <div className="text-muted-foreground text-xs">{label}</div>
                                <div>{value ?? '-'}</div>
                            </div>
                        ))}
                    </div>
                </div>

                {doc.attendance && (
                    <div className={cardClass}>
                        <h3 className="mb-1 font-medium">Attendance</h3>
                        <div className="grid gap-2 text-sm sm:grid-cols-3 lg:grid-cols-6">
                            {[
                                ['Present', doc.attendance.present_days],
                                ['Absent', doc.attendance.absent_days],
                                ['Paid leave', doc.attendance.paid_leave_days],
                                ['Unpaid leave', doc.attendance.unpaid_leave_days],
                                ['Late occurrences', doc.attendance.late_occurrences],
                                ['Days deducted', doc.attendance.deductible_days],
                            ].map(([label, value]) => (
                                <div key={String(label)}>
                                    <div className="text-muted-foreground text-xs">{label}</div>
                                    <div>{String(value)}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <LineTable title="Earnings" lines={doc.earnings} total={doc.totals.total_earnings} />
                <LineTable title="Deductions" lines={doc.deductions} total={doc.totals.total_deductions} />

                <div className={cardClass}>
                    <div className="flex items-center justify-between">
                        <span className="font-medium">Net payable</span>
                        <span className="text-lg font-medium">{money(doc.totals.net)}</span>
                    </div>
                </div>

                {doc.payment.events.length > 0 && (
                    <div className={cardClass}>
                        <h3 className="mb-3 font-medium">Payments</h3>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className={headClass}>
                                        <th className="py-2">Date</th>
                                        <th className="py-2">Event</th>
                                        <th className="py-2">Method</th>
                                        <th className="py-2">Reference</th>
                                        <th className="py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {doc.payment.events.map((event, index) => (
                                        <tr key={index} className={rowClass}>
                                            <td className="py-2 whitespace-nowrap">{event.date}</td>
                                            <td className="py-2">
                                                {event.type}
                                                {event.failure_reason && <span className="text-destructive ml-1">- {event.failure_reason}</span>}
                                            </td>
                                            <td className="py-2">{event.method}</td>
                                            <td className="py-2">{event.reference ?? '-'}</td>
                                            <td className="py-2 text-right">{money(event.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {doc.notes.length > 0 && (
                    <div className={cardClass}>
                        <h3 className="mb-2 font-medium">How this payslip was reached</h3>
                        <ul className="text-muted-foreground list-inside list-disc text-sm">
                            {doc.notes.map((note, index) => (
                                <li key={index}>{note}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <p className="text-muted-foreground text-xs">
                    Generated {doc.generated.at}
                    {doc.generated.by ? ` by ${doc.generated.by}` : ''}. Confidential salary document.
                </p>
            </div>
        </AppLayout>
    );
}
