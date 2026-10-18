import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The manager's team view (Module 5, Slice 1). Read-only on purpose:
 * approving stays with the Approvals inbox, the single approval surface.
 */
interface PendingLeave {
    id: number;
    type: string | null;
    start_date: string | null;
    end_date: string | null;
    days: string | number;
    reason: string | null;
}

interface TeamMember {
    id: number;
    employee_code: string;
    name: string;
    designation: string | null;
    today: string | null;
    pending_leave: PendingLeave[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My team', href: '/hrm/my-team' }];

const todayLabel: Record<string, string> = {
    present: 'Present',
    absent: 'Absent',
    on_leave: 'On leave',
    holiday: 'Holiday',
    weekend: 'Weekend',
    field_duty: 'Field duty',
    half_day: 'Half day',
    late: 'Late',
    needs_review: 'Needs review',
};

export default function MyTeam({ team }: { team: TeamMember[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My team" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">My team</h1>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/approvals">Open approvals</Link>
                    </Button>
                </div>

                {team.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Nobody reports to you in the current assignments. Reporting lines are set on the employee's transfer form.
                    </p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {team.map((member) => (
                            <div key={member.id} className="border-border bg-card space-y-2 rounded-lg border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">{member.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {member.employee_code}
                                            {member.designation && ` · ${member.designation}`}
                                        </p>
                                    </div>
                                    <span
                                        className={
                                            'rounded-full px-2 py-0.5 text-xs font-medium ' +
                                            (member.today === 'present' || member.today === 'field_duty'
                                                ? 'bg-emerald-500/10 text-emerald-700'
                                                : member.today === 'absent'
                                                  ? 'bg-destructive/10 text-destructive'
                                                  : 'bg-muted text-muted-foreground')
                                        }
                                    >
                                        {member.today ? (todayLabel[member.today] ?? member.today) : 'No record yet'}
                                    </span>
                                </div>

                                {member.pending_leave.length > 0 && (
                                    <div className="space-y-1 border-t pt-2">
                                        <p className="text-muted-foreground text-xs font-medium">Waiting for a decision</p>
                                        {member.pending_leave.map((leave) => (
                                            <p key={leave.id} className="text-sm">
                                                {leave.type ?? 'Leave'}: {showDate(leave.start_date)} to {showDate(leave.end_date)} ({leave.days}d)
                                                {leave.reason && <span className="text-muted-foreground"> · {leave.reason}</span>}
                                            </p>
                                        ))}
                                    </div>
                                )}

                                <div className="flex gap-2 border-t pt-2">
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={`/hrm/employees/${member.id}`}>Profile</Link>
                                    </Button>
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={`/hrm/attendance/employees/${member.id}`}>Attendance</Link>
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
