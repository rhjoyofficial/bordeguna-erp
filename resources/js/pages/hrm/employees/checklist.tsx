import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

/**
 * The joiner / leaver checklist (Module 5, Slice 4). Every tick records who
 * and when; a mis-click is reversible. The offboarding list leads with a
 * reminder only when the person has actually left.
 */
interface ChecklistItem {
    id: number;
    title: string;
    done: boolean;
    done_by: string | null;
    done_at: string | null;
}

interface ChecklistProps {
    employee: { id: number; employee_code: string; name: string; is_separated: boolean };
    onboarding: ChecklistItem[];
    offboarding: ChecklistItem[];
    still_holds: { id: number; tag: string; name: string; quantity: number }[];
    can_move_assets: boolean;
}

function ItemList({ items, employeeId }: { items: ChecklistItem[]; employeeId: number }) {
    return (
        <div className="space-y-1.5">
            {items.map((item) => (
                <label key={item.id} className="hover:bg-muted/40 flex cursor-pointer items-start gap-2 rounded-md p-1.5">
                    <input
                        type="checkbox"
                        className="mt-0.5"
                        checked={item.done}
                        onChange={() => router.post(`/hrm/employees/${employeeId}/checklist/${item.id}/toggle`, {}, { preserveScroll: true })}
                    />
                    <span className="text-sm">
                        <span className={item.done ? 'text-muted-foreground line-through' : ''}>{item.title}</span>
                        {item.done && item.done_by && (
                            <span className="text-muted-foreground block text-xs">
                                {item.done_by} · {item.done_at?.slice(0, 16).replace('T', ' ')}
                            </span>
                        )}
                    </span>
                </label>
            ))}
        </div>
    );
}

export default function Checklist({ employee, onboarding, offboarding, still_holds, can_move_assets }: ChecklistProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: employee.name, href: `/hrm/employees/${employee.id}` },
        { title: 'Checklist', href: `/hrm/employees/${employee.id}/checklist` },
    ];

    const doneCount = (items: ChecklistItem[]) => items.filter((item) => item.done).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Checklist - ${employee.employee_code}`} />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        Checklist: {employee.name} ({employee.employee_code})
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        What must actually happen for a joiner and a leaver. Every tick records who and when; untick a mis-click and it is as if it
                        never was.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="border-border bg-card rounded-lg border p-4">
                        <h2 className="mb-3 font-semibold">
                            Onboarding{' '}
                            <span className="text-muted-foreground text-sm font-normal">
                                {doneCount(onboarding)}/{onboarding.length}
                            </span>
                        </h2>
                        <ItemList items={onboarding} employeeId={employee.id} />
                    </div>

                    <div
                        className={
                            'rounded-lg border p-4 ' + (employee.is_separated ? 'border-amber-500/40 bg-amber-500/5' : 'border-border bg-card')
                        }
                    >
                        <h2 className="mb-3 font-semibold">
                            Offboarding{' '}
                            <span className="text-muted-foreground text-sm font-normal">
                                {doneCount(offboarding)}/{offboarding.length}
                            </span>
                        </h2>
                        {employee.is_separated && doneCount(offboarding) < offboarding.length && (
                            <p className="mb-2 text-sm font-medium text-amber-700">This person has left - finish the list.</p>
                        )}
                        <ItemList items={offboarding} employeeId={employee.id} />

                        {/* ⚠ Current custody from the asset register (slice A2): a
                            person who has left and still holds a laptop is the one
                            line an offboarding list must never miss. */}
                        <div className="mt-4 border-t pt-3 text-sm">
                            <p className="font-medium">
                                Still holds from the asset register <span className="text-muted-foreground font-normal">({still_holds.length})</span>
                            </p>
                            {still_holds.length === 0 ? (
                                <p className="text-muted-foreground mt-1">Nothing.</p>
                            ) : (
                                <ul className="mt-1 grid gap-1">
                                    {still_holds.map((thing) => (
                                        <li key={`${thing.id}-${thing.tag}`}>
                                            <a href={`/assets/items/${thing.id}`} className="underline underline-offset-2">
                                                {thing.tag}
                                            </a>{' '}
                                            {thing.name}
                                            {thing.quantity > 1 && ` x ${thing.quantity}`}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {can_move_assets && still_holds.length > 0 && (
                                <a
                                    href={`/assets/movements/create?kind=return&${still_holds.map((t) => `items[]=${t.id}`).join('&')}`}
                                    className="mt-2 inline-block underline underline-offset-2"
                                >
                                    Record the return
                                </a>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
