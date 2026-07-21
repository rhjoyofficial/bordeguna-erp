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
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface Assignment {
    id: number;
    is_primary: boolean;
    office: string | null;
    department: string | null;
    section: string | null;
    designation: string | null;
    effective_from: string | null;
    effective_to: string | null;
}

interface EmployeeDetail {
    id: number;
    employee_code: string;
    name: string;
    employment_type: string;
    status: string;
    separation_type: string | null;
    separation_date: string | null;
    separation_reason: string | null;
    personal_email: string | null;
    phone: string | null;
    national_id: string | null;
    date_of_birth: string | null;
    joining_date: string | null;
    user: { name: string; email: string } | null;
    assignments: Assignment[];
}

interface ShowProps {
    employee: EmployeeDetail;
    transitionable_statuses: Option[];
    can_separate: boolean;
    separation_types: Option[];
}

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? '—'}</dd>
        </div>
    );
}

function SeparateDialog({ employeeId, separationTypes }: { employeeId: number; separationTypes: Option[] }) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(separationTypes[0]?.value ?? '');
    const [date, setDate] = useState('');
    const [reason, setReason] = useState('');

    const submit = () => {
        router.post(
            route('hrm.employees.separate', employeeId),
            { separation_type: type, separation_date: date, separation_reason: reason },
            { preserveScroll: true, onSuccess: () => setOpen(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Separate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Separate employee</DialogTitle>
                    <DialogDescription>
                        Records how and when they left, ends their assignments, and disables any ERP login. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="separation_type">Type</Label>
                        <select id="separation_type" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                            {separationTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="separation_date">Last working date</Label>
                        <Input id="separation_date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="separation_reason">Reason (optional)</Label>
                        <textarea
                            id="separation_reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring min-h-20 rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={submit} disabled={type === '' || date === ''}>
                        Separate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function EmployeeShow({ employee, transitionable_statuses, can_separate, separation_types }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.employee_code, href: `/hrm/employees/${employee.id}` },
    ];

    const setStatus = (status: string) => router.patch(route('hrm.employees.status', employee.id), { status }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={employee.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{employee.name}</h2>
                        <p className="text-sm text-muted-foreground">
                            {employee.employee_code} · {employee.employment_type}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                            {employee.status.replace('_', ' ')}
                        </Badge>
                        {transitionable_statuses.map((target) => (
                            <Button key={target.value} variant="outline" size="sm" onClick={() => setStatus(target.value)}>
                                {target.value === 'active' ? 'Set active' : `Set ${target.label.toLowerCase()}`}
                            </Button>
                        ))}
                        {can_separate && <SeparateDialog employeeId={employee.id} separationTypes={separation_types} />}
                    </div>
                </div>

                {employee.status === 'separated' && (
                    <div className="border-destructive/40 rounded-xl border bg-destructive/5 p-4">
                        <h3 className="mb-3 text-sm font-medium text-destructive">Separation</h3>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Field label="Type" value={employee.separation_type} />
                            <Field label="Last working date" value={employee.separation_date} />
                            <Field label="Reason" value={employee.separation_reason} />
                        </dl>
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Details</h3>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Field label="Personal email" value={employee.personal_email} />
                        <Field label="Phone" value={employee.phone} />
                        <Field label="National ID" value={employee.national_id} />
                        <Field label="Date of birth" value={employee.date_of_birth} />
                        <Field label="Joining date" value={employee.joining_date} />
                        <Field label="ERP login" value={employee.user ? employee.user.email : 'Not linked'} />
                    </dl>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Assignments</h3>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                <th className="py-2">Office</th>
                                <th className="py-2">Department</th>
                                <th className="py-2">Section</th>
                                <th className="py-2">Designation</th>
                                <th className="py-2">From</th>
                                <th className="py-2">To</th>
                            </tr>
                        </thead>
                        <tbody>
                            {employee.assignments.map((assignment) => (
                                <tr key={assignment.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {assignment.office ?? '—'}
                                        {assignment.is_primary && <span className="ml-2 text-xs text-muted-foreground">(primary)</span>}
                                    </td>
                                    <td className="py-2 text-muted-foreground">{assignment.department ?? '—'}</td>
                                    <td className="py-2 text-muted-foreground">{assignment.section ?? '—'}</td>
                                    <td className="py-2 text-muted-foreground">{assignment.designation ?? '—'}</td>
                                    <td className="py-2 text-muted-foreground">{assignment.effective_from ?? '—'}</td>
                                    <td className="py-2 text-muted-foreground">{assignment.effective_to ?? 'Current'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
