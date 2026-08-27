import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Reason {
    id: number;
    context: string;
    name: string;
    is_active: boolean;
}

interface ReasonsProps {
    reasons: Reason[];
    contexts: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reasons', href: '/settings/reasons' }];

function AddReasonForm({ context }: { context: string }) {
    const { data, setData, post, errors, processing, reset } = useForm({ context, name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('reasons.store'), { onSuccess: () => reset('name') });
    };

    return (
        <form onSubmit={submit} className="flex items-end gap-3">
            <div className="grid flex-1 gap-2">
                <Label htmlFor={`new-reason-${context}`}>New reason</Label>
                <Input id={`new-reason-${context}`} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Damaged" />
                <InputError message={errors.name} />
            </div>
            <Button disabled={processing}>Add</Button>
        </form>
    );
}

function ReasonRow({ reason }: { reason: Reason }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, errors, processing, reset } = useForm({ name: reason.name });

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('reasons.update', reason.id), { onSuccess: () => setEditing(false) });
    };

    const toggleActive = () => {
        router.patch(route('reasons.toggle-active', reason.id));
    };

    if (editing) {
        return (
            <form onSubmit={save} className="flex items-center gap-2 py-2">
                <div className="flex-1">
                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                    <InputError message={errors.name} />
                </div>
                <Button type="submit" size="sm" disabled={processing}>
                    Save
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        reset();
                        setEditing(false);
                    }}
                >
                    Cancel
                </Button>
            </form>
        );
    }

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex items-center justify-between border-b py-2 last:border-0">
            <div className="flex items-center gap-2">
                <span className={reason.is_active ? '' : 'text-muted-foreground line-through'}>{reason.name}</span>
                {!reason.is_active && (
                    <Badge variant="outline" className="text-muted-foreground">
                        Inactive
                    </Badge>
                )}
            </div>
            <div className="flex items-center gap-2">
                <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                    Rename
                </Button>
                <Button variant="ghost" size="sm" onClick={toggleActive}>
                    {reason.is_active ? 'Deactivate' : 'Reactivate'}
                </Button>
            </div>
        </div>
    );
}

export default function Reasons({ reasons, contexts }: ReasonsProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reasons" />

            <SettingsLayout>
                <div className="space-y-10">
                    <HeadingSmall
                        title="Reasons"
                        description="Manage the reason lists used for stock adjustments and transfers. Reasons are deactivated, never deleted, so past records stay intact."
                    />

                    {Object.entries(contexts).map(([context, label]) => {
                        const contextReasons = reasons.filter((reason) => reason.context === context);

                        return (
                            <div key={context} className="space-y-4">
                                <h3 className="text-sm font-medium">{label}</h3>

                                <AddReasonForm context={context} />

                                <div>
                                    {contextReasons.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">No reasons yet.</p>
                                    ) : (
                                        contextReasons.map((reason) => <ReasonRow key={reason.id} reason={reason} />)
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
