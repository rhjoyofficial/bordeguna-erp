import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

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
    event: 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100',
};

const EMPTY = {
    title: '',
    description: '',
    location: '',
    starts_at: '',
    ends_at: '',
    all_day: true as boolean,
    is_private: false as boolean,
};

export default function CalendarIndex({
    month,
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
    const [editing, setEditing] = useState<Item | null>(null);

    const form = useForm(EMPTY);

    const selectedDay = days.find((d) => d.date === selected);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing?.id) {
            form.put(route('calendar.update', editing.id), { preserveScroll: true, onSuccess: () => cancel() });

            return;
        }

        form.post(route('calendar.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const cancel = () => {
        setEditing(null);
        form.clearErrors();
        form.reset();
    };

    const startNew = (date: string) => {
        setEditing(null);
        form.clearErrors();
        form.setData({ ...EMPTY, starts_at: `${date}T09:00` });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{monthLabel}</h2>
                        <p className="text-muted-foreground text-sm">
                            Holidays, approved leave, follow-ups, deliveries expected and money falling due, gathered from the modules that own them.
                            You only see what you could already open elsewhere.
                        </p>
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
                    <h3 className="font-medium">{selectedDay ? selectedDay.date : 'Pick a day'}</h3>

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
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditing(item);
                                                        form.setData({ ...EMPTY, title: item.title, starts_at: `${item.date}T09:00` });
                                                    }}
                                                >
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

                    {can.manage && selectedDay && (
                        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="title" required>
                                    What is happening
                                </Label>
                                <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                                <InputError message={form.errors.title} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="starts_at" required>
                                    Starts
                                </Label>
                                <Input
                                    id="starts_at"
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) => form.setData('starts_at', e.target.value)}
                                    onFocus={() => !form.data.starts_at && startNew(selectedDay.date)}
                                />
                                <InputError message={form.errors.starts_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ends_at">Ends (optional)</Label>
                                <Input
                                    id="ends_at"
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    onChange={(e) => form.setData('ends_at', e.target.value)}
                                />
                                <InputError message={form.errors.ends_at} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="location">Where</Label>
                                <Input id="location" value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
                            </div>
                            <div className="flex items-end gap-4 md:col-span-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.data.all_day} onChange={(e) => form.setData('all_day', e.target.checked)} />
                                    All day
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_private}
                                        onChange={(e) => form.setData('is_private', e.target.checked)}
                                    />
                                    Only me
                                </label>
                            </div>
                            <div className="flex items-end gap-2 md:col-span-4">
                                <Button type="submit" disabled={form.processing}>
                                    {editing ? 'Save' : 'Add to the calendar'}
                                </Button>
                                {editing && (
                                    <Button type="button" variant="ghost" onClick={cancel}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
