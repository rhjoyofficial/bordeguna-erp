import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FileBarChart } from 'lucide-react';

interface ReportRow {
    key: string;
    name: string;
    description: string;
}

interface Props {
    // Grouped server-side ('Attendance', 'Payroll'), already filtered to what
    // this user may run — including Correction C10d's ACL-02 rule, which is why
    // a Department Manager simply has no Payroll group rather than a disabled
    // one. Showing a report you may not run only invites the question.
    groups: Record<string, ReportRow[]>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HRM', href: '/hrm/employees' },
    { title: 'Reports', href: '/hrm/reports' },
];

export default function ReportsIndex({ groups }: Props) {
    const names = Object.keys(groups);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-muted-foreground text-sm">
                        Every report can be exported to Excel, CSV or PDF, or opened as a print view.
                    </p>
                </div>

                {names.length === 0 && (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                        You do not have access to any reports.
                    </div>
                )}

                {names.map((group) => (
                    <div key={group} className="flex flex-col gap-3">
                        <h2 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{group}</h2>

                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {groups[group].map((report) => (
                                <Link
                                    key={report.key}
                                    href={`/hrm/reports/${report.key}`}
                                    className="hover:bg-accent flex flex-col gap-2 rounded-lg border p-4 transition-colors"
                                >
                                    <div className="flex items-center gap-2">
                                        <FileBarChart className="text-muted-foreground size-4 shrink-0" />
                                        <span className="font-medium">{report.name}</span>
                                    </div>
                                    <p className="text-muted-foreground text-sm">{report.description}</p>
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
