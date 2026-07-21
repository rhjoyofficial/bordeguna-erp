import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

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
    personal_email: string | null;
    phone: string | null;
    national_id: string | null;
    date_of_birth: string | null;
    joining_date: string | null;
    user: { name: string; email: string } | null;
    assignments: Assignment[];
}

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

function Field({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? '—'}</dd>
        </div>
    );
}

export default function EmployeeShow({ employee }: { employee: EmployeeDetail }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.employee_code, href: `/hrm/employees/${employee.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={employee.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{employee.name}</h2>
                        <p className="text-sm text-muted-foreground">
                            {employee.employee_code} · {employee.employment_type}
                        </p>
                    </div>
                    <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                        {employee.status.replace('_', ' ')}
                    </Badge>
                </div>

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
