import { HrCorrectionActions, type HrActions } from '@/components/hrm/hr-correction-actions';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { my, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface NoticeRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    subject: string;
    context: { window_label?: string; occurrence?: number; month?: string; dates?: string[] } | null;
    issued_by: string | null;
    issued_at: string;
    // ⭐ Corrections wave 3 (24 Oct 2026): Withdraw.
    withdrawn_on: string | null;
    withdraw_reason: string | null;
    actions: HrActions;
}

interface Props {
    filters: { employee_id: number | null };
    notices: NoticeRow[];
    employees: { id: number; name: string }[];
    reasons?: Record<string, ReasonOption[]>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Daily attendance', href: '/hrm/attendance/daily' },
    { title: 'Show cause notices', href: '/hrm/notices' },
];

/**
 * Issue a notice from scratch - the blank-letter door. The prefilled door
 * lives on the Daily attendance grid, where the late day carries its own
 * window, occurrence and dates into the template.
 */
function IssueNoticeDialog({ employees }: { employees: { id: number; name: string }[] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () =>
        router.post(
            route('hrm.notices.store'),
            { employee_id: Number(employeeId), subject, body },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setSubject('');
                    setBody('');
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Issue notice</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Issue a show cause notice</DialogTitle>
                    <DialogDescription>
                        The notice goes on permanent record and the employee is notified in the ERP. It cannot be edited after issuing.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="scn_employee" required>
                            Employee
                        </Label>
                        <SearchSelect id="scn_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="scn_subject" required>
                            Subject
                        </Label>
                        <Input
                            id="scn_subject"
                            aria-required
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="Show cause notice"
                        />
                        {errors.subject && <p className="text-destructive text-xs">{errors.subject}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="scn_body" required>
                            Notice text
                        </Label>
                        <textarea
                            id="scn_body"
                            aria-required
                            className="border-input bg-background min-h-40 w-full rounded-md border px-3 py-2 text-sm"
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                        />
                        {errors.body && <p className="text-destructive text-xs">{errors.body}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={employeeId === '' || subject.trim() === '' || body.trim() === ''}>
                        Issue
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function NoticesIndex({ filters, notices, employees, reasons = {} }: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Show cause notices" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Show cause notices</h2>
                    <div className="flex items-center gap-2">
                        <SearchSelect
                            value={filters.employee_id ?? ''}
                            onChange={(e) =>
                                router.get(
                                    route('hrm.notices.index'),
                                    { employee_id: e.target.value === '' ? null : Number(e.target.value) },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <IssueNoticeDialog employees={employees} />
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-3">
                    {notices.length === 0 ? (
                        <p className="text-muted-foreground p-2 text-sm">No notices issued.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-1.5">Issued</th>
                                    <th className="py-1.5">Employee</th>
                                    <th className="py-1.5">Subject</th>
                                    <th className="py-1.5">About</th>
                                    <th className="py-1.5">Issued by</th>
                                    <th className="py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {notices.map((notice) => (
                                    <tr key={notice.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-1.5 whitespace-nowrap">{showDate(notice.issued_at)}</td>
                                        <td className="py-1.5">
                                            {notice.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{notice.employee_code}</span>
                                        </td>
                                        <td className="py-1.5">
                                            {notice.subject}
                                            {notice.withdrawn_on && (
                                                <span className="mt-0.5 flex flex-col items-start gap-0.5">
                                                    <Badge variant="outline">{t('corr.hrm.withdrawn_on_date', { date: notice.withdrawn_on })}</Badge>
                                                    {notice.withdraw_reason && (
                                                        <span className="text-muted-foreground text-xs">{notice.withdraw_reason}</span>
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-1.5 text-xs">
                                            {notice.context?.window_label
                                                ? `${notice.context.window_label}${notice.context.month ? `, ${my(notice.context.month)}` : ''}`
                                                : '-'}
                                        </td>
                                        <td className="text-muted-foreground py-1.5">{notice.issued_by}</td>
                                        <td className="py-1.5 text-right">
                                            <span className="inline-flex flex-wrap items-start justify-end gap-2">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={route('hrm.notices.show', notice.id)}>View / print</Link>
                                                </Button>
                                                <HrCorrectionActions
                                                    kind="notice"
                                                    id={notice.id}
                                                    label={`${notice.subject}, ${notice.employee ?? ''}`}
                                                    actions={notice.actions}
                                                    reasons={reasons}
                                                    url={route('hrm.notices.withdraw', notice.id)}
                                                />
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
