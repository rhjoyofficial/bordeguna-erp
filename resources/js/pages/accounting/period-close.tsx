import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * Period close (Module 2, Slice 1): the one moving boundary before which the
 * books are sealed. Closing is an act with a note; unlocking is an EVENT with
 * a required reason, never a quiet toggle - and the checklist informs the
 * human without ever blocking them.
 */
interface ChecklistRow {
    label: string;
    value: string;
    warns: boolean;
}

interface PeriodCloseProps {
    boundary: string | null;
    closed_by: string | null;
    note: string | null;
    is_unlocked: boolean;
    unlock_note: string | null;
    unlocked_by: string | null;
    can_close: boolean;
    checklist: ChecklistRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Period close', href: '/accounting/period-close' }];

export default function PeriodClose({ boundary, closed_by, note, is_unlocked, unlock_note, unlocked_by, can_close, checklist }: PeriodCloseProps) {
    const closeForm = useForm({ closed_through: boundary ?? '', note: '' });
    const unlockForm = useForm({ reason: '' });

    const submitClose: FormEventHandler = (e) => {
        e.preventDefault();
        closeForm.post('/accounting/period-close');
    };

    const submitUnlock: FormEventHandler = (e) => {
        e.preventDefault();
        unlockForm.post('/accounting/period-close/unlock');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Period close" />
            <div className="max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Period close</h1>
                </div>

                <section className="border-border bg-card rounded-lg border p-4">
                    <h2 className="mb-2 text-sm font-semibold">Where the boundary stands</h2>
                    {boundary === null ? (
                        <p className="text-muted-foreground text-sm">The books have never been closed - every date is still open.</p>
                    ) : (
                        <div className="space-y-1 text-sm">
                            <p>
                                Closed through <span className="font-semibold">{boundary}</span>
                                {closed_by && <span className="text-muted-foreground"> by {closed_by}</span>}
                            </p>
                            {note && <p className="text-muted-foreground text-xs italic">"{note}"</p>}
                            {is_unlocked && (
                                <p className="text-destructive text-xs font-medium">
                                    ⚠ Currently UNLOCKED{unlocked_by ? ` by ${unlocked_by}` : ''}
                                    {unlock_note ? `: "${unlock_note}"` : ''} - close again when the correction is done.
                                </p>
                            )}
                        </div>
                    )}
                </section>

                <section className="border-border bg-card rounded-lg border p-4">
                    <h2 className="mb-2 text-sm font-semibold">Before you close</h2>
                    <ul className="space-y-1.5">
                        {checklist.map((row, index) => (
                            <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
                                <span className={row.warns ? 'font-medium' : ''}>{row.label}</span>
                                <span className={row.warns ? 'text-destructive font-semibold' : 'text-muted-foreground'}>
                                    {row.warns ? '⚠ ' : ''}
                                    {row.value}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                {can_close && (
                    <div className="grid gap-4 md:grid-cols-2">
                        <form onSubmit={submitClose} className="border-border bg-card space-y-3 rounded-lg border p-4">
                            <h2 className="text-sm font-semibold">{is_unlocked ? 'Close again (relock)' : 'Close the books'}</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="closed_through" required>
                                    Closed through (inclusive)
                                </Label>
                                <Input
                                    id="closed_through"
                                    type="date"
                                    value={closeForm.data.closed_through}
                                    onChange={(e) => closeForm.setData('closed_through', e.target.value)}
                                />
                                <InputError message={closeForm.errors.closed_through} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="close_note">Note</Label>
                                <Input
                                    id="close_note"
                                    value={closeForm.data.note}
                                    onChange={(e) => closeForm.setData('note', e.target.value)}
                                    placeholder="e.g. August finalised after the real pay run"
                                />
                                <InputError message={closeForm.errors.note} />
                            </div>
                            <Button type="submit" disabled={closeForm.processing}>
                                Close the books
                            </Button>
                        </form>

                        <form onSubmit={submitUnlock} className="border-border bg-card space-y-3 rounded-lg border p-4">
                            <h2 className="text-sm font-semibold">Unlock for a correction</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="reason" required>
                                    Reason
                                </Label>
                                <Input
                                    id="reason"
                                    value={unlockForm.data.reason}
                                    onChange={(e) => unlockForm.setData('reason', e.target.value)}
                                    placeholder="e.g. the accountant found a missing August bill"
                                />
                                <InputError message={unlockForm.errors.reason} />
                            </div>
                            <Button type="submit" variant="outline" disabled={unlockForm.processing || boundary === null}>
                                Unlock
                            </Button>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
