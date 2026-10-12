import { DoneFields, emptyDone, textareaClass, whoPayload, type DoneValues } from '@/components/assets/maintenance-fields';
import InputError from '@/components/input-error';
import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Maintenance', href: '/assets/maintenance' },
    { title: 'Record', href: '/assets/maintenance/create' },
];

type Form = DoneValues & {
    item_id: string;
    plan_id: string;
    kind: string;
    problem: string;
    reported_on: string;
    out_of_service: boolean;
    done: boolean;
    photos: File[];
};

/**
 * Record a fault, or work done (slice A4). Work already finished is recorded
 * as done in the same step; a fault stays open until somebody closes it.
 */
export default function AssetMaintenanceCreate({
    items,
    plans,
    preselected,
    kinds,
    conditions,
    users,
    parties,
    vouchers,
    sees_money,
    today,
}: {
    items: (SelectOption & { status: string })[];
    plans: (SelectOption & { item_id: number })[];
    preselected: { item: number | null; plan: number | null };
    kinds: SelectOption[];
    conditions: SelectOption[];
    users: SelectOption[];
    parties: SelectOption[];
    vouchers: SelectOption[];
    sees_money: boolean;
    today: string;
}) {
    const plan = plans.find((candidate) => Number(candidate.value) === preselected.plan);
    const { data, setData, post, errors, processing, transform } = useForm<Form>({
        ...emptyDone(today),
        item_id: preselected.item ? String(preselected.item) : '',
        plan_id: plan ? plan.value : '',
        kind: plan ? 'preventive' : 'repair',
        problem: plan ? plan.label : '',
        reported_on: today,
        out_of_service: false,
        // Planned care is usually recorded after it is done.
        done: !!plan,
        photos: [],
    });

    const itemPlans = plans.filter((candidate) => String(candidate.item_id) === data.item_id);
    const chosen = items.find((item) => item.value === data.item_id);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform(({ who, ...rest }) => ({
            ...rest,
            ...whoPayload(who),
            plan_id: rest.plan_id || null,
            condition_after: rest.condition_after || null,
            cost: rest.cost || null,
            expense_voucher_id: rest.expense_voucher_id || null,
            // A fault left open takes no "done" fields.
            ...(rest.done ? {} : { done_on: null, work_done: null }),
        }));
        post('/assets/maintenance', { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record maintenance" />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Record work or a fault</h1>
                </div>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label required>Which thing</Label>
                        <SearchableSelect
                            value={data.item_id}
                            onChange={(v) => setData((current) => ({ ...current, item_id: v, plan_id: '' }))}
                            options={items}
                            placeholder="Tag or name"
                        />
                        {chosen && <p className="text-muted-foreground text-xs">Now: {chosen.status}</p>}
                        <InputError message={errors.item_id ?? (errors as Record<string, string | undefined>).item} />
                    </div>
                    <div className="grid gap-2">
                        <Label required>Kind</Label>
                        <SearchableSelect value={data.kind} onChange={(v) => setData('kind', v)} options={kinds} />
                        <InputError message={errors.kind} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reported_on" required>
                            Noticed or started on
                        </Label>
                        <Input
                            id="reported_on"
                            aria-required
                            type="date"
                            max={today}
                            value={data.reported_on}
                            onChange={(e) => setData('reported_on', e.target.value)}
                        />
                        <InputError message={errors.reported_on} />
                    </div>
                    {itemPlans.length > 0 && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label>For which plan</Label>
                            <SearchableSelect
                                value={data.plan_id}
                                onChange={(v) => setData('plan_id', v)}
                                options={[{ value: '', label: 'Not for a plan' }, ...itemPlans]}
                            />
                            <InputError message={errors.plan_id} />
                        </div>
                    )}
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="problem" required>
                            What is wrong, or what is to be done
                        </Label>
                        <textarea
                            id="problem"
                            aria-required
                            className={textareaClass}
                            value={data.problem}
                            onChange={(e) => setData('problem', e.target.value)}
                        />
                        <InputError message={errors.problem} />
                    </div>
                </section>

                <section className="grid gap-3">
                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox checked={data.done} onCheckedChange={(on) => setData('done', on === true)} className="mt-0.5" />
                        <span>Already done</span>
                    </label>
                    {!data.done && (
                        <label className="flex items-start gap-2 text-sm">
                            <Checkbox
                                checked={data.out_of_service}
                                onCheckedChange={(on) => setData('out_of_service', on === true)}
                                className="mt-0.5"
                            />
                            <span>
                                Out of service until fixed
                                <span className="text-muted-foreground block text-xs">
                                    Sets it Under repair; closing this record puts the old status back.
                                </span>
                            </span>
                        </label>
                    )}
                    {data.done && (
                        <DoneFields
                            values={data}
                            set={(field, value) => setData(field, value)}
                            errors={errors}
                            users={users}
                            parties={parties}
                            conditions={conditions}
                            vouchers={vouchers}
                            seesMoney={sees_money}
                            today={today}
                        />
                    )}
                </section>

                <div className="grid gap-2">
                    <Label htmlFor="photos">Photos</Label>
                    <input
                        id="photos"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        className="w-full min-w-0 text-sm"
                        onChange={(e) => setData('photos', Array.from(e.target.files ?? []).slice(0, 6))}
                    />
                    <InputError message={errors.photos ?? (errors as Record<string, string | undefined>)['photos.0']} />
                </div>

                <InputError message={(errors as Record<string, string | undefined>).record} />

                <div className="flex flex-wrap gap-2">
                    <Button disabled={processing || !data.item_id}>{data.done ? 'Record as done' : 'Record as open'}</Button>
                    <Button variant="outline" asChild>
                        <Link href="/assets/maintenance">Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
