import { textareaClass, whoOptions, whoPayload } from '@/components/assets/maintenance-fields';
import InputError from '@/components/input-error';
import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Plan {
    id: number;
    title: string;
    instructions: string | null;
    every_count: number;
    every_unit: string;
    next_due_on: string;
    remind_days: number;
    assignee_user_id: number | null;
    party_id: number | null;
    is_active: boolean;
}

/**
 * Add or change a maintenance plan for one thing (slice A4).
 */
export default function AssetMaintenancePlanForm({
    item,
    plan,
    units,
    users,
    parties,
    today,
}: {
    item: { id: number; tag: string; name: string };
    plan: Plan | null;
    units: SelectOption[];
    users: SelectOption[];
    parties: SelectOption[];
    today: string;
}) {
    const who = plan?.assignee_user_id ? `u:${plan.assignee_user_id}` : plan?.party_id ? `p:${plan.party_id}` : '';
    const { data, setData, post, put, errors, processing, transform } = useForm({
        item_id: item.id,
        title: plan?.title ?? '',
        instructions: plan?.instructions ?? '',
        every_count: String(plan?.every_count ?? 1),
        every_unit: plan?.every_unit ?? 'month',
        next_due_on: plan?.next_due_on ?? today,
        remind_days: String(plan?.remind_days ?? 3),
        who,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        { title: item.tag, href: `/assets/items/${item.id}` },
        { title: plan ? 'Change plan' : 'Plan care', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform(({ who: chosen, ...rest }) => {
            const { done_by_user_id, party_id } = whoPayload(chosen);

            return { ...rest, assignee_user_id: done_by_user_id, party_id };
        });

        if (plan) {
            put(`/assets/maintenance/plans/${plan.id}`);
        } else {
            post('/assets/maintenance/plans');
        }
    };

    const fieldErrors = errors as Record<string, string | undefined>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={plan ? 'Change plan' : 'Plan care'} />
            <form onSubmit={submit} className="flex max-w-2xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{plan ? 'Change plan' : 'Plan care'}</h1>
                    <p className="text-muted-foreground text-sm">
                        <span className="font-mono">{item.tag}</span> {item.name}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="title" required>
                            What should be done
                        </Label>
                        <Input
                            id="title"
                            aria-required
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Clean the print head"
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="every_count" required>
                            Every
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id="every_count"
                                aria-required
                                type="number"
                                min={1}
                                max={366}
                                value={data.every_count}
                                onChange={(e) => setData('every_count', e.target.value)}
                                className="w-20"
                            />
                            <SearchableSelect fit value={data.every_unit} onChange={(v) => setData('every_unit', v)} options={units} />
                        </div>
                        <InputError message={errors.every_count ?? errors.every_unit} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="next_due_on" required>
                            Next due on
                        </Label>
                        <Input
                            id="next_due_on"
                            type="date"
                            aria-required
                            value={data.next_due_on}
                            onChange={(e) => setData('next_due_on', e.target.value)}
                        />
                        <InputError message={errors.next_due_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="remind_days" required>
                            Remind this many days ahead
                        </Label>
                        <Input
                            id="remind_days"
                            aria-required
                            type="number"
                            min={0}
                            max={90}
                            value={data.remind_days}
                            onChange={(e) => setData('remind_days', e.target.value)}
                            className="w-24"
                        />
                        <InputError message={errors.remind_days} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Who does it</Label>
                        <SearchableSelect value={data.who} onChange={(v) => setData('who', v)} options={whoOptions(users, parties)} />
                        <InputError message={fieldErrors.assignee_user_id ?? fieldErrors.party_id} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="instructions">How</Label>
                        <textarea
                            id="instructions"
                            className={textareaClass}
                            value={data.instructions}
                            onChange={(e) => setData('instructions', e.target.value)}
                        />
                        <InputError message={errors.instructions} />
                    </div>
                </div>

                <InputError message={fieldErrors.item ?? fieldErrors.plan} />

                <div className="flex flex-wrap gap-2">
                    <Button disabled={processing}>{plan ? 'Save' : 'Add plan'}</Button>
                    <Button variant="outline" asChild>
                        <Link href={`/assets/items/${item.id}`}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
