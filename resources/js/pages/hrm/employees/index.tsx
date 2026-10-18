import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { LayoutGrid, List } from 'lucide-react';
import { useState } from 'react';

interface EmployeeRow {
    id: number;
    employee_code: string;
    name: string;
    photo_url: string | null;
    employment_type: string;
    status: string;
    office: string | null;
    designation: string | null;
    separation_type?: string | null;
    separation_date?: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employees', href: '/hrm/employees' }];

const statusVariant: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    draft: 'secondary',
    active: 'default',
    on_leave: 'outline',
    suspended: 'destructive',
    separated: 'destructive',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

function EmployeeCard({ employee }: { employee: EmployeeRow }) {
    return (
        <Link
            href={route('hrm.employees.show', employee.id)}
            className="border-border bg-card hover:border-ring flex flex-col items-center gap-2 rounded-lg border p-4 text-center transition-colors"
        >
            {employee.photo_url ? (
                <img src={employee.photo_url} alt={employee.name} className="h-14 w-14 rounded-full object-cover" />
            ) : (
                <span className="bg-primary/10 text-primary flex h-14 w-14 items-center justify-center rounded-full text-lg font-semibold">
                    {initials(employee.name)}
                </span>
            )}
            <div>
                <p className="font-medium">{employee.name}</p>
                <p className="text-muted-foreground text-xs">{employee.employee_code}</p>
            </div>
            <p className="text-muted-foreground text-sm">{employee.designation ?? '-'}</p>
            <p className="text-muted-foreground text-xs">{employee.office ?? '-'}</p>
            <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                {employee.status.replace('_', ' ')}
            </Badge>
            {employee.separation_date && <p className="text-muted-foreground text-xs">Left {showDate(employee.separation_date)}</p>}
        </Link>
    );
}

interface ProbationOverdueRow {
    id: number;
    employee_code: string;
    name: string;
    joining_date: string;
    probation_months: number;
    probation_ended_on: string;
}

export default function EmployeesIndex({
    employees,
    probationOverdue = [],
    scope = 'current',
    formerCount = 0,
}: {
    employees: EmployeeRow[];
    probationOverdue: ProbationOverdueRow[];
    scope?: 'current' | 'former';
    formerCount?: number;
}) {
    const [view, setView] = useState<'table' | 'cards'>(() => {
        if (typeof window === 'undefined') return 'table';
        return window.localStorage.getItem('employees:view') === 'cards' ? 'cards' : 'table';
    });

    const switchView = (next: 'table' | 'cards') => {
        setView(next);
        window.localStorage.setItem('employees:view', next);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employees" />
            <div className="flex flex-col gap-4 p-4">
                {/* Probation tracking: probations that ran out with nobody
                    confirmed. Computed fresh - no flag to go stale. */}
                {probationOverdue.length > 0 && (
                    <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4">
                        <p className="text-sm font-semibold text-amber-700">
                            {probationOverdue.length === 1
                                ? 'One probation has ended without a confirmation'
                                : `${probationOverdue.length} probations have ended without a confirmation`}
                        </p>
                        <div className="mt-2 space-y-1">
                            {probationOverdue.map((row) => (
                                <p key={row.id} className="text-sm">
                                    <Link className="font-medium underline-offset-2 hover:underline" href={`/hrm/employees/${row.id}`}>
                                        {row.employee_code} · {row.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        - {row.probation_months} months ended {showDate(row.probation_ended_on)}. Open the profile to confirm or
                                        extend.
                                    </span>
                                </p>
                            ))}
                        </div>
                    </div>
                )}
                <div className="flex items-center gap-2 border-b">
                    <Link
                        href="/hrm/employees"
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                            scope === 'current' ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        Current employees
                    </Link>
                    <Link
                        href="/hrm/employees?scope=former"
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                            scope === 'former' ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        Former employees{formerCount > 0 ? ` (${formerCount})` : ''}
                    </Link>
                </div>
                <div className="flex items-center justify-between">
                    <p className="text-muted-foreground text-sm">
                        {scope === 'former' ? `${employees.length} former` : `${employees.length} in this business`}
                    </p>
                    <div className="flex items-center gap-2">
                        <div className="border-border flex rounded-md border p-0.5">
                            <button
                                type="button"
                                onClick={() => switchView('table')}
                                aria-pressed={view === 'table'}
                                aria-label="Table view"
                                className={cn(
                                    'rounded-sm p-1.5 transition-colors',
                                    view === 'table' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <List className="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() => switchView('cards')}
                                aria-pressed={view === 'cards'}
                                aria-label="Card view"
                                className={cn(
                                    'rounded-sm p-1.5 transition-colors',
                                    view === 'cards' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <LayoutGrid className="h-4 w-4" />
                            </button>
                        </div>
                        <Button asChild>
                            <Link href={route('hrm.employees.create')}>Add employee</Link>
                        </Button>
                    </div>
                </div>

                {view === 'cards' ? (
                    employees.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-center text-sm">No employees yet. Add your first team member.</p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {employees.map((employee) => (
                                <EmployeeCard key={employee.id} employee={employee} />
                            ))}
                        </div>
                    )
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
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
                                        <Link href={route('hrm.employees.show', employee.id)} className="text-primary font-medium hover:underline">
                                            {employee.employee_code}
                                        </Link>
                                    </td>
                                    <td className="py-2">{employee.name}</td>
                                    <td className="text-muted-foreground py-2">{employee.employment_type}</td>
                                    <td className="text-muted-foreground py-2">{employee.office ?? '-'}</td>
                                    <td className="text-muted-foreground py-2">{employee.designation ?? '-'}</td>
                                    <td className="py-2">
                                        <Badge variant={statusVariant[employee.status] ?? 'secondary'} className="capitalize">
                                            {employee.status.replace('_', ' ')}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                            {employees.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground py-4 text-center">
                                        No employees yet. Add your first team member.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}
