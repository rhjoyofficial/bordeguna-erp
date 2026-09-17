import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * MY WORK (Module 1, Slice 3): the day on one page. Each card holds the first
 * few of its kind and links to the screen where the work is actually done -
 * this page decides nothing itself, it only gathers what waits.
 */
interface ApprovalItem {
    type: string;
    summary: string;
    requested_by: string | null;
    since: string | null;
}

interface Section<T> {
    count: number;
    items: T[];
}

interface MyWorkProps {
    approvals: Section<ApprovalItem>;
    followups: Section<{ title: string; due: string }>;
    todos: Section<{ title: string; due_on: string | null }>;
    my_requests: Section<{ type: string; since: string | null }>;
    attendance?: { needs_review: number; holds_payroll: number };
    recurring?: Section<{ label: string; due_on: string; amount: string }>;
    assets?: { in_transit: number; unacknowledged: number; open_gaps: number; maintenance_due: number; open_repairs: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My work', href: '/work' }];

function Card({
    title,
    count,
    href,
    linkLabel,
    children,
    empty,
}: {
    title: string;
    count: number;
    href: string;
    linkLabel: string;
    children: React.ReactNode;
    empty: string;
}) {
    return (
        <section className="border-border bg-card rounded-lg border p-4">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    {title}
                    {count > 0 && <span className="bg-primary/10 text-primary ml-2 rounded-full px-2 py-0.5 text-xs font-bold">{count}</span>}
                </h2>
                <Link href={href} className="text-muted-foreground text-xs underline-offset-2 hover:underline">
                    {linkLabel}
                </Link>
            </div>
            {count === 0 ? <p className="text-muted-foreground text-sm">{empty}</p> : <ul className="space-y-2">{children}</ul>}
        </section>
    );
}

export default function MyWork({ approvals, followups, todos, my_requests, attendance, recurring, assets }: MyWorkProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My work" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">My work</h1>
                    <p className="text-muted-foreground text-sm">Everything waiting on you, gathered from every module.</p>
                </div>

                {assets && assets.open_gaps > 0 && (
                    <Link href="/assets/counts" className="block rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        <span className="font-semibold">Stock-take gaps to settle:</span> {assets.open_gaps}
                    </Link>
                )}

                {assets && (assets.maintenance_due > 0 || assets.open_repairs > 0) && (
                    <Link href="/assets/maintenance" className="block rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        <span className="font-semibold">Maintenance waiting:</span>
                        {assets.maintenance_due > 0 && ` ${assets.maintenance_due} planned ${assets.maintenance_due === 1 ? 'job' : 'jobs'} due`}
                        {assets.maintenance_due > 0 && assets.open_repairs > 0 && ','}
                        {assets.open_repairs > 0 && ` ${assets.open_repairs} open ${assets.open_repairs === 1 ? 'fault or job' : 'faults or jobs'}`}
                    </Link>
                )}

                {assets && (assets.in_transit > 0 || assets.unacknowledged > 0) && (
                    <Link href="/assets/movements?open=1" className="block rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        <span className="font-semibold">Asset hand-overs waiting:</span>
                        {assets.in_transit > 0 && ` ${assets.in_transit} on the way and not yet received`}
                        {assets.in_transit > 0 && assets.unacknowledged > 0 && ','}
                        {assets.unacknowledged > 0 && ` ${assets.unacknowledged} handed over and not acknowledged for 3 days`}
                    </Link>
                )}

                {attendance && (attendance.needs_review > 0 || attendance.holds_payroll > 0) && (
                    <Link href="/hrm/daily-attendance" className="border-destructive/40 bg-destructive/5 block rounded-lg border p-3 text-sm">
                        <span className="font-semibold">Attendance needs review:</span> {attendance.needs_review}{' '}
                        {attendance.needs_review === 1 ? 'day' : 'days'} flagged
                        {attendance.holds_payroll > 0 && (
                            <span>
                                , <span className="font-semibold">{attendance.holds_payroll} holding payroll</span>
                            </span>
                        )}
                        {' - open the daily view.'}
                    </Link>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card
                        title="Approvals waiting for me"
                        count={approvals.count}
                        href="/approvals"
                        linkLabel="Open approvals"
                        empty="Nothing waits on your signature."
                    >
                        {approvals.items.map((item, index) => (
                            <li key={index} className="text-sm">
                                <span className="font-medium">{item.type}</span>
                                <span className="text-muted-foreground"> · {item.summary}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {item.requested_by ?? 'Unknown'} · {item.since ?? ''}
                                </span>
                            </li>
                        ))}
                    </Card>

                    <Card
                        title="Follow-ups due"
                        count={followups.count}
                        href="/crm/follow-ups"
                        linkLabel="Open follow-ups"
                        empty="No follow-up is due or overdue."
                    >
                        {followups.items.map((item, index) => (
                            <li key={index} className="text-sm">
                                <span className="font-medium">{item.title}</span>
                                <span className="text-muted-foreground block text-xs">{item.due}</span>
                            </li>
                        ))}
                    </Card>

                    <Card title="My to-dos" count={todos.count} href="/notes" linkLabel="Open notes" empty="Your list is clear.">
                        {todos.items.map((item, index) => (
                            <li key={index} className="text-sm">
                                <span className="font-medium">{item.title}</span>
                                {item.due_on && <span className="text-muted-foreground block text-xs">due {item.due_on}</span>}
                            </li>
                        ))}
                    </Card>

                    <Card
                        title="Waiting on others"
                        count={my_requests.count}
                        href="/hrm/my-leave"
                        linkLabel="My requests"
                        empty="Nothing you asked for is stuck with somebody else."
                    >
                        {my_requests.items.map((item, index) => (
                            <li key={index} className="text-sm">
                                <span className="font-medium">{item.type}</span>
                                <span className="text-muted-foreground block text-xs">requested {item.since ?? ''}</span>
                            </li>
                        ))}
                    </Card>

                    {recurring && (
                        <Card
                            title="Standing costs due this week"
                            count={recurring.count}
                            href="/purchasing/recurring-expenses"
                            linkLabel="Open schedule"
                            empty="Nothing falls due in the next seven days."
                        >
                            {recurring.items.map((item, index) => (
                                <li key={index} className="text-sm">
                                    <span className="font-medium">{item.label}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        due {item.due_on} · {Number(item.amount).toLocaleString()} BDT
                                    </span>
                                </li>
                            ))}
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
