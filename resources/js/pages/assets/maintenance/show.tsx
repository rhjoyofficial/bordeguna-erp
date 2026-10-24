import { AssetAction, pick, type AssetAvailability, type ReasonOption } from '@/components/assets/asset-action';
import { DoneFields, emptyDone, whoPayload, type DoneValues } from '@/components/assets/maintenance-fields';
import { type MaintenanceRecordRow } from '@/components/assets/maintenance-lists';
import InputError from '@/components/input-error';
import { SearchableSelect, type SelectOption } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface MaintenanceRecord extends MaintenanceRecordRow {
    plan: { id: number; title: string } | null;
    item_status: string | null;
    item_condition: string | null;
    status_before: string | null;
    closed_by: string | null;
    closed_at: string | null;
    cancel_reason: string | null;
    reopened_at: string | null;
    reopen_reason: string | null;
    expense_voucher_id: number | null;
    photos: { id: number; url: string; name: string }[];
}

interface Options {
    conditions: SelectOption[];
    users: SelectOption[];
    parties: SelectOption[];
    vouchers: SelectOption[];
    sees_money: boolean;
    today: string;
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words whitespace-pre-line">{children}</dd>
        </div>
    );
}

function Complete({ record, options }: { record: MaintenanceRecord; options: Options }) {
    const { data, setData, post, errors, processing, transform } = useForm<DoneValues>(emptyDone(options.today));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform(({ who, ...rest }) => ({
            ...rest,
            ...whoPayload(who),
            condition_after: rest.condition_after || null,
            cost: rest.cost || null,
            expense_voucher_id: rest.expense_voucher_id || null,
        }));
        post(`/assets/maintenance/${record.id}/complete`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 rounded-lg border p-3">
            <h2 className="font-medium">Close as done</h2>
            <DoneFields
                values={data}
                set={(field, value) => setData(field, value)}
                errors={errors}
                users={options.users}
                parties={options.parties}
                conditions={options.conditions}
                vouchers={options.vouchers}
                seesMoney={options.sees_money}
                today={options.today}
            />
            <InputError message={(errors as { record?: string }).record} />
            <div>
                <Button disabled={processing}>Close as done</Button>
            </div>
        </form>
    );
}

function Cancel({ record }: { record: MaintenanceRecord }) {
    const { data, setData, post, errors, processing } = useForm({ cancel_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/maintenance/${record.id}/cancel`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-lg border p-3">
            <h2 className="font-medium">Cancel</h2>
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    value={data.cancel_reason}
                    onChange={(e) => setData('cancel_reason', e.target.value)}
                    aria-required
                    placeholder="Why"
                    className="w-full sm:w-80"
                />
                <Button variant="outline" disabled={processing || !data.cancel_reason.trim()}>
                    Cancel it
                </Button>
            </div>
            <InputError message={errors.cancel_reason ?? (errors as { record?: string }).record} />
        </form>
    );
}

function Money({ record, options }: { record: MaintenanceRecord; options: Options }) {
    const { data, setData, post, errors, processing } = useForm({
        cost: record.cost ?? '',
        expense_voucher_id: record.expense_voucher_id ? String(record.expense_voucher_id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/maintenance/${record.id}/money`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-3 rounded-lg border p-3">
            <h2 className="font-medium">Cost</h2>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="money_cost">What it cost</Label>
                    <Input id="money_cost" type="number" min={0} step="0.01" value={data.cost} onChange={(e) => setData('cost', e.target.value)} />
                    <InputError message={errors.cost} />
                </div>
                <div className="grid gap-2">
                    <Label>Paid by expense voucher</Label>
                    <SearchableSelect
                        value={data.expense_voucher_id}
                        onChange={(v) => setData('expense_voucher_id', v)}
                        options={[{ value: '', label: 'Not paid yet, or not by voucher' }, ...options.vouchers]}
                    />
                    <InputError message={errors.expense_voucher_id} />
                </div>
            </div>
            <div>
                <Button variant="outline" disabled={processing}>
                    Save cost
                </Button>
            </div>
        </form>
    );
}

function Photos({ record, canManage }: { record: MaintenanceRecord; canManage: boolean }) {
    const { setData, post, errors, processing, reset } = useForm<{ photos: File[] }>({ photos: [] });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/maintenance/${record.id}/photos`, { forceFormData: true, preserveScroll: true, onSuccess: () => reset('photos') });
    };

    return (
        <section className="grid gap-2">
            <h2 className="font-medium">Photos</h2>
            {record.photos.length === 0 ? (
                <p className="text-muted-foreground text-sm">None.</p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {record.photos.map((file) => (
                        <a key={file.id} href={file.url} target="_blank" rel="noreferrer">
                            <img src={file.url} alt={file.name} className="size-28 rounded-md border object-cover" />
                        </a>
                    ))}
                </div>
            )}
            {canManage && (
                <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        required
                        aria-required
                        className="w-full min-w-0 text-sm sm:w-auto"
                        onChange={(e) => setData('photos', Array.from(e.target.files ?? []).slice(0, 6))}
                    />
                    <Button size="sm" variant="outline" disabled={processing}>
                        Add
                    </Button>
                    <InputError message={errors.photos ?? (errors as Record<string, string | undefined>)['photos.0']} />
                </form>
            )}
        </section>
    );
}

/**
 * One maintenance record (slice A4): what was wrong, what was done, and, for
 * the financial register only, what it cost.
 */
export default function AssetMaintenanceShow({
    record,
    can_manage,
    actions,
    reasons,
    ...options
}: Options & {
    record: MaintenanceRecord;
    can_manage: boolean;
    // ⭐ Corrections wave 3 (24 Oct 2026): Reopen a done record (the server
    // carries it as "reverse"; the button says Reopen).
    actions: AssetAvailability[];
    reasons: { reversal: ReasonOption[] };
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        { title: 'Maintenance', href: '/assets/maintenance' },
        { title: record.number, href: `/assets/maintenance/${record.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${record.number} ${record.kind_label}`} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-lg font-semibold">
                            {record.number} {record.kind_label}
                        </h1>
                        <p className="text-sm">
                            <Link href={`/assets/items/${record.item_id}`} className="font-mono text-xs underline underline-offset-2">
                                {record.tag}
                            </Link>{' '}
                            {record.name}
                            <span className="text-muted-foreground">
                                {' '}
                                - now {record.item_status}, {record.item_condition}
                            </span>
                        </p>
                    </div>
                    <div className="flex flex-wrap items-start gap-2">
                        <AssetAction
                            availability={pick(actions, 'reverse')}
                            label={t('corr.assets.reopen')}
                            title={t('corr.assets.stays', {
                                action: t('corr.assets.reopen'),
                                number: record.number,
                                state: t('corr.assets.state_reopened'),
                            })}
                            description={t('corr.assets.reopen_note')}
                            url={`/assets/maintenance/${record.id}/reopen`}
                            reasons={reasons.reversal}
                        />
                        <Badge variant={record.state === 'open' ? 'default' : 'secondary'}>{record.state_label}</Badge>
                    </div>
                </div>
                {record.reopened_at && (
                    <p className="text-muted-foreground text-sm">
                        {t('corr.assets.reopened', { date: showDate(record.reopened_at) || '-', reason: record.reopen_reason ?? '-' })}
                    </p>
                )}

                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Fact label="What was wrong, or to be done">{record.problem}</Fact>
                    </div>
                    <Fact label="Reported">
                        {showDate(record.reported_on)}
                        {record.reported_by && ` by ${record.reported_by}`}
                    </Fact>
                    {record.plan && <Fact label="Plan">{record.plan.title}</Fact>}
                    {record.out_of_service && (
                        <Fact label="Out of service">
                            {record.status_before ? `Set Under repair; was ${record.status_before}` : 'Was already out of use'}
                        </Fact>
                    )}
                    {record.state === 'done' && (
                        <>
                            <Fact label="Done">
                                {showDate(record.done_on)}
                                {record.done_by && ` by ${record.done_by}`}
                            </Fact>
                            <div className="sm:col-span-2">
                                <Fact label="What was done">{record.work_done}</Fact>
                            </div>
                            {record.condition_after && <Fact label="Condition afterwards">{record.condition_after}</Fact>}
                        </>
                    )}
                    {record.state === 'cancelled' && <Fact label="Why cancelled">{record.cancel_reason}</Fact>}
                    {record.closed_at && (
                        <Fact label="Closed">
                            {showDate(record.closed_at)}
                            {record.closed_by && ` by ${record.closed_by}`}
                        </Fact>
                    )}
                    {options.sees_money && (
                        <Fact label="Cost">
                            {record.cost ?? 'Not recorded'}
                            {record.voucher && (
                                <>
                                    {' '}
                                    paid by{' '}
                                    <Link
                                        href={`/purchasing/expenses?search=${encodeURIComponent(record.voucher.number)}`}
                                        className="underline underline-offset-2"
                                    >
                                        {record.voucher.number}
                                    </Link>
                                </>
                            )}
                        </Fact>
                    )}
                </dl>

                <Photos record={record} canManage={can_manage} />

                {record.state === 'open' && can_manage && (
                    <>
                        <Complete record={record} options={options} />
                        <Cancel record={record} />
                    </>
                )}

                {record.state !== 'cancelled' && options.sees_money && record.state !== 'open' && <Money record={record} options={options} />}
            </div>
        </AppLayout>
    );
}
