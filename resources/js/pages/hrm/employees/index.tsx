import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface EmployeeRow {
    id: number;
    employee_code: string;
    name: string;
    employment_type: string;
    status: string;
    office: string | null;
    designation: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employees', href: '/hrm/employees' }];

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

export default function EmployeesIndex({ employees }: { employees: EmployeeRow[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employees" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <p className="text-sm text-muted-foreground">{employees.length} in this business</p>
                    <Button asChild>
                        <Link href={route('hrm.employees.create')}>Add employee</Link>
                    </Button>
                </div>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Code</th>
                            <th className="py-2">Name</th>
                            <th className="py-2">Type</th>
                            <th className="py-2">Office</th>
                            <th className="py-2">Designation</th>
                            <th className="py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {employees.map((employee) => (
                            <tr key={employee.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <Link href={route('hrm.employees.show', employee.id)} className="font-medium text-primary hover:underline">
                                        {employee.employee_code}
                                    </Link>
                                </td>
                                <td className="py-2">{employee.name}</td>
                                <td className="py-2 text-muted-foreground">{employee.employment_type}</td>
                                <td className="py-2 text-muted-foreground">{employee.office ?? '—'}</td>
                                <td className="py-2 text-muted-foreground">{employee.designation ?? '—'}</td>
                                <td className="py-2">
                                    <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                                        {employee.status.replace('_', ' ')}
                                    </Badge>
                                </td>
                            </tr>
                        ))}
                        {employees.length === 0 && (
                            <tr>
                                <td colSpan={6} className="py-4 text-center text-muted-foreground">
                                    No employees yet. Add your first team member.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
