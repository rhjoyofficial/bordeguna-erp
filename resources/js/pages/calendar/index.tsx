import { CalendarItemDialog } from '@/components/calendar/calendar-item-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Item {
    date: string;
    type: string;
    label: string;
    title: string;
    detail: string | null;
    url: string | null;
    id: number | null;
}

interface Day {
    date: string;
    day: number;
    in_month: boolean;
    is_today: boolean;
    items: Item[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Calendar', href: '/calendar' }];

const WEEKDAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

/**
 * One colour per kind of thing, so a month can be read without reading it.
 * Money out and money in are deliberately the two most distinct.
 */
const TONE: Record<string, string> = {
    holiday: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
    leave: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    follow_up: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-100',
    sales_order: 'bg-violet-100 text-violet-900 dark:bg-violet-950 dark:text-violet-100',
    purchase_order: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-950 dark:text-indigo-100',
    invoice_due: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-100',
    bill_due: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
    loan_due_in: 'bg-teal-100 text-teal-900 dark:bg-teal-950 dark:text-teal-100',
    loan_due_out: 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-100',
    loan_maturity: 'bg-orange-100 text-orange-900 dark:bg-orange-950 dark:text-orange-100',
    event: 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100',
};

export default function CalendarIndex({
    monthLabel,
    previous,
    next,
    days,
    can,
}: {
    month: string;
    monthLabel: string;
    previous: string;
    next: string;
    days: Day[];
    can: { manage: boolean };
}) {
    const today = days.find((d) => d.is_today)?.date ?? days.find((d) => d.in_month)?.date ?? days[0]?.date;
    const [selected, setSelected] = useState<string>(today);
    // ⚠ Add and edit happen in a popup opened from the day or the entry (Joy,
    // 14 Oct 2026: "click add button pop up will add ... same when edit").
    // `undefined` = closed, `null` = a new entry on the picked day.
    const [dialog, setDialog] = useState<Item | null | undefined>(undefined);

    const selectedDay = days.find((d) => d.date === selected);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{monthLabel}</h2>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/calendar?month=${previous}`}>Previous</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/calendar">Today</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/calendar?month=${next}`}>Next</Link>
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <div className="bg-border grid min-w-[52rem] grid-cols-7 gap-px rounded-lg border">
                        {WEEKDAYS.map((w) => (
                            <div key={w} className="bg-muted/50 p-2 text-center text-xs font-medium">
                                {w}
                            </div>
                        ))}
                        {days.map((day) => (
                            <button
                                key={day.date}
                                type="button"
                                onClick={() => setSelected(day.date)}
                                className={[
                                    'bg-background min-h-24 p-2 text-left align-top',
                                    day.in_month ? '' : 'opacity-45',
                                    day.date === selected ? 'ring-primary ring-2 ring-inset' : '',
                                ].join(' ')}
                            >
                                <div className="flex items-center justify-between">
                                    <span className={day.is_today ? 'bg-primary text-primary-foreground rounded px-1.5 text-sm' : 'text-sm'}>
                                        {day.day}
                                    </span>
                                    {day.items.length > 3 && <span className="text-muted-foreground text-xs">+{day.items.length - 3}</span>}
                                </div>
                                <div className="mt-1 flex flex-col gap-1">
                                    {day.items.slice(0, 3).map((item, i) => (
                                        <span key={i} className={`truncate rounded px-1 py-0.5 text-xs ${TONE[item.type] ?? TONE.event}`}>
                                            {item.title}
                                        </span>
                                    ))}
                                </div>
                            </button>
                        ))}
                    </div>
                </div>

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-medium">{selectedDay ? selectedDay.date : 'Pick a day'}</h3>
                        {can.manage && selectedDay && (
                            <Button type="button" onClick={() => setDialog(null)}>
                                Add to this day
                            </Button>
                        )}
                    </div>

                    {selectedDay && selectedDay.items.length === 0 && <p className="text-muted-foreground text-sm">Nothing on this day.</p>}

                    <div className="flex flex-col gap-2">
                        {selectedDay?.items.map((item, i) => {
                            // ⚠ Pulled out of the JSX. Narrowing `item.id` with
                            // `&&` does not survive into a click handler, because
                            // the closure re-reads the property rather than the
                            // narrowed value.
                            const eventId = item.id;

                            return (
                                <div key={i} className="flex flex-wrap items-center gap-3 rounded-lg border p-3">
                                    <Badge className={TONE[item.type] ?? TONE.event}>{item.label}</Badge>
                                    <span className="font-medium">{item.title}</span>
                                    {item.detail && <span className="text-muted-foreground text-sm">{item.detail}</span>}
                                    <div className="ml-auto flex gap-2">
                                        {item.url && (
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={item.url}>Open</Link>
                                            </Button>
                                        )}
                                        {can.manage && eventId !== null && (
                                            <>
                                                <Button variant="ghost" size="sm" onClick={() => setDialog(item)}>
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => router.delete(route('calendar.destroy', eventId), { preserveScroll: true })}
                                                >
                                                    Delete
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            </div>

            {can.manage && selectedDay && (
                <CalendarItemDialog
                    open={dialog !== undefined}
                    onOpenChange={(open) => !open && setDialog(undefined)}
                    date={selectedDay.date}
                    item={dialog ?? null}
                />
            )}
        </AppLayout>
    );
}
