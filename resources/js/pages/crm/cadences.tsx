import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

/**
 * Cadences (Module 4, Slice 3): standing rhythms that write follow-up tasks.
 * The queue and the overdue digest already carry the tasks; this screen only
 * decides the rhythm. Paused, never deleted.
 */
interface CadenceRow {
    id: number;
    name: string;
    trigger: string;
    delay_days: number;
    task_title: string;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cadences', href: '/crm/cadences' }];

const triggerLabel: Record<string, string> = {
    lead_created: 'When a new lead arrives',
    customer_dormant: 'When a customer goes quiet',
};

export default function Cadences({ cadences }: { cadences: CadenceRow[] }) {
    const form = useForm({
        name: '',
        trigger: 'lead_created',
        delay_days: '1',
        task_title: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cadences" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Follow-up cadences</h1>
                    <p className="text-muted-foreground text-sm">
                        Standing rhythms that create tasks by themselves: a new lead gets its first call, a customer with no order in N
                        days gets one nudge - never a second while the first is open. Tasks land on the follow-up queue and the overdue
                        digest as always.
                    </p>
                </div>

                <form
                    className="border-border bg-card grid max-w-4xl gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/crm/cadences', { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <div className="space-y-1">
                        <Label className="text-xs">Name</Label>
                        <Input placeholder="e.g. First call" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Rhythm</Label>
                        <Select value={form.data.trigger} onValueChange={(value) => form.setData('trigger', value)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="lead_created">New lead arrives</SelectItem>
                                <SelectItem value="customer_dormant">Customer goes quiet</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">{form.data.trigger === 'lead_created' ? 'Task due after (days)' : 'Quiet for (days)'}</Label>
                        <Input type="number" min="1" max="365" value={form.data.delay_days} onChange={(e) => form.setData('delay_days', e.target.value)} />
                        {form.errors.delay_days && <p className="text-destructive text-xs">{form.errors.delay_days}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Task says</Label>
                        <Input
                            placeholder="e.g. Call the new lead"
                            value={form.data.task_title}
                            onChange={(e) => form.setData('task_title', e.target.value)}
                        />
                        {form.errors.task_title && <p className="text-destructive text-xs">{form.errors.task_title}</p>}
                    </div>
                    <div className="flex items-end">
                        <Button type="submit" disabled={form.processing}>
                            Start cadence
                        </Button>
                    </div>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Cadence</th>
                                <th className="px-3 py-2 font-medium">Rhythm</th>
                                <th className="px-3 py-2 text-right font-medium">Days</th>
                                <th className="px-3 py-2 font-medium">Task</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {cadences.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-4 text-center">
                                        No cadences yet - follow-ups are all manual until one runs.
                                    </td>
                                </tr>
                            ) : (
                                cadences.map((cadence) => (
                                    <tr key={cadence.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{cadence.name}</td>
                                        <td className="px-3 py-2">{triggerLabel[cadence.trigger] ?? cadence.trigger}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{cadence.delay_days}</td>
                                        <td className="text-muted-foreground px-3 py-2">{cadence.task_title}</td>
                                        <td className="px-3 py-2">{cadence.is_active ? 'Running' : 'Paused'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.post(`/crm/cadences/${cadence.id}/toggle`, {}, { preserveScroll: true })}
                                            >
                                                {cadence.is_active ? 'Pause' : 'Resume'}
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
