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
    // ⭐ 27 Oct 2026: the member's leave year, the same figures as HR's register.
    leave: {
        types: Record<number, { got: number; used: number; left: number }>;
        paid_leave: number;
        unpaid_leave: number;
        absent: number;
    } | null;
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

export default function MyTeam({
    team,
    leave_year,
    leave_types = [],
}: {
    team: TeamMember[];
    leave_year: number;
    leave_types?: { id: number; name: string }[];
}) {
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
                    <p className="text-muted-foreground text-sm">Nobody reports to you.</p>
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

                                {member.leave && (
                                    <div className="space-y-1 border-t pt-2 text-sm">
                                        <p className="text-muted-foreground text-xs font-medium">Leave in {leave_year}</p>
                                        {leave_types
                                            .filter(
                                                (type) =>
                                                    (member.leave?.types[type.id]?.got ?? 0) !== 0 || (member.leave?.types[type.id]?.used ?? 0) > 0,
                                            )
                                            .map((type) => {
                                                const cell = member.leave!.types[type.id];
                                                return (
                                                    <p key={type.id}>
                                                        {type.name}: <span className="font-medium">{cell.left} left</span>
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            of {cell.got}, {cell.used} used
                                                        </span>
                                                    </p>
                                                );
                                            })}
                                        <p className="text-muted-foreground text-xs">
                                            Paid leave {member.leave.paid_leave} · Unpaid leave {member.leave.unpaid_leave} · Absent{' '}
                                            {member.leave.absent}
                                        </p>
                                    </div>
                                )}

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
