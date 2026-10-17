import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';

export interface MaintenancePlanRow {
    id: number;
    title: string;
    every: string;
    next_due_on: string;
    overdue: boolean;
    due_soon: boolean;
    last_done_on: string | null;
    who: string | null;
    is_active: boolean;
    // Present on the maintenance list, where plans of many things are shown.
    item_id?: number;
    tag?: string | null;
    name?: string | null;
}

export interface MaintenanceRecordRow {
    id: number;
    number: string;
    item_id: number;
    tag: string | null;
    name: string | null;
    kind: string;
    kind_label: string;
    state: string;
    state_label: string;
    problem: string;
    reported_by: string | null;
    reported_on: string;
    out_of_service: boolean;
    done_on: string | null;
    work_done: string | null;
    condition_after: string | null;
    done_by: string | null;
    cost: string | null;
    voucher: { id: number; number: string } | null;
}

function ItemLink({ id, tag, name }: { id?: number; tag?: string | null; name?: string | null }) {
    if (!id) {
        return null;
    }

    return (
        <>
            <Link href={`/assets/items/${id}`} className="font-mono text-xs underline underline-offset-2">
                {tag}
            </Link>{' '}
            {name}{' '}
        </>
    );
}

/**
 * Maintenance plans (slice A4): what should be done, how often, when next.
 */
export function PlanList({ plans, canManage, withItem = false }: { plans: MaintenancePlanRow[]; canManage: boolean; withItem?: boolean }) {
    if (plans.length === 0) {
        return <p className="text-muted-foreground text-sm">None.</p>;
    }

    return (
        <ul className="grid gap-2">
            {plans.map((plan) => (
                <li key={plan.id} className="rounded-lg border p-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="min-w-0">
                            {withItem && <ItemLink id={plan.item_id} tag={plan.tag} name={plan.name} />}
                            <span className="font-medium">{plan.title}</span>
                        </span>
                        {!plan.is_active ? (
                            <Badge variant="outline">Paused</Badge>
                        ) : plan.overdue ? (
                            <Badge variant="destructive">Overdue since {plan.next_due_on}</Badge>
                        ) : plan.due_soon ? (
                            <Badge>Due {plan.next_due_on}</Badge>
                        ) : (
                            <Badge variant="secondary">Next {plan.next_due_on}</Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        {plan.every}
                        {plan.who && ` - ${plan.who}`}
                        {plan.last_done_on ? ` - last done ${plan.last_done_on}` : ' - not done yet'}
                    </p>
                    {canManage && (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {plan.is_active && plan.item_id !== undefined && (
                                <Button size="sm" asChild>
                                    <Link href={`/assets/maintenance/create?item=${plan.item_id}&plan=${plan.id}`}>Record it done</Link>
                                </Button>
                            )}
                            <Button size="sm" variant="outline" asChild>
                                <Link href={`/assets/maintenance/plans/${plan.id}/edit`}>Change</Link>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.post(`/assets/maintenance/plans/${plan.id}/active`, { active: !plan.is_active }, { preserveScroll: true })
                                }
                            >
                                {plan.is_active ? 'Pause' : 'Resume'}
                            </Button>
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Maintenance records (slice A4): faults reported and work done. Money shows
 * only when the server sent it, which it does only to the financial register.
 */
export function RecordList({ records, withItem = false }: { records: MaintenanceRecordRow[]; withItem?: boolean }) {
    if (records.length === 0) {
        return <p className="text-muted-foreground text-sm">None.</p>;
    }

    return (
        <ul className="grid gap-2">
            {records.map((record) => (
                <li key={record.id}>
                    <Link href={`/assets/maintenance/${record.id}`} className="block rounded-lg border p-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="min-w-0">
                                <span className="font-mono text-xs">{record.number}</span>{' '}
                                {withItem && (
                                    <span className="font-mono text-xs">
                                        {record.tag} <span className="font-sans">{record.name}</span>{' '}
                                    </span>
                                )}
                                <span className="font-medium">{record.kind_label}</span>
                            </span>
                            <Badge variant={record.state === 'open' ? 'default' : record.state === 'done' ? 'secondary' : 'outline'}>
                                {record.state === 'done' ? `Done ${record.done_on}` : record.state_label}
                            </Badge>
                        </div>
                        <p className="mt-1 break-words">{record.state === 'done' && record.work_done ? record.work_done : record.problem}</p>
                        <p className="text-muted-foreground text-xs">
                            Reported {record.reported_on}
                            {record.reported_by && ` by ${record.reported_by}`}
                            {record.out_of_service && record.state === 'open' && ' - out of service'}
                            {record.done_by && ` - done by ${record.done_by}`}
                            {record.cost !== null && ` - cost ${formatMoney(record.cost)}`}
                            {record.voucher && ` (${record.voucher.number})`}
                        </p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
