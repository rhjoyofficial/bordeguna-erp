import { LoginsCard, type LoginsCardData } from '@/components/assets/logins-card';
import { PlanList, RecordList, type MaintenancePlanRow, type MaintenanceRecordRow } from '@/components/assets/maintenance-lists';
import { ThingDialog, type EditingThing, type ThingFormOptions } from '@/components/assets/thing-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Item {
    id: number;
    tag: string;
    name: string;
    category: string | null;
    tracking: string;
    tracking_label: string;
    quantity: number;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    specification: string | null;
    location: string;
    status: string;
    status_label: string;
    condition: string;
    condition_label: string;
    parent: { id: number; tag: string; name: string } | null;
    parts: { id: number; tag: string; name: string; status_label: string }[];
    acquired_on: string | null;
    warranty_ends_on: string | null;
    warranty_expired: boolean;
    service_ends_on: string | null;
    service_expired: boolean;
    service_party: string | null;
    supplier: string | null;
    purchase_price: string | null;
    note: string | null;
    registered_by: string | null;
    registered_at: string | null;
    tag_url: string;
    qr_svg: string;
    custody: string;
    custody_since: string | null;
    acknowledged_at: string | null;
    in_transit: { id: number; number: string } | null;
    holdings: { holder: string; quantity: number }[];
    can_issue: boolean;
    can_return: boolean;
    can_reassign: boolean;
    can_receive_back: boolean;
    can_send_out: boolean;
    can_transfer: boolean;
    // With an outside party: the send-out that put it there (13 Oct 2026).
    outside: {
        party: string | null;
        since: string;
        expected_back_on: string | null;
        overdue: boolean;
        movement: { id: number; number: string };
    } | null;
    open_count: { id: number; number: string; on_list: boolean; found: boolean | null } | null;
}

interface MovementRow {
    id: number;
    number: string;
    kind_label: string;
    status_label: string;
    from: string;
    to: string;
    quantity: number;
    moved_on: string;
    by: string | null;
}

interface PhotoGroup {
    kind: string;
    label: string;
    uploadable: boolean;
    files: { id: number; url: string; name: string }[];
}

interface HistoryEntry {
    id: number;
    when: string | null;
    who: string | null;
    what: string;
    changes: { field: string; from: string | null; to: string | null }[];
}

/*
 * ⚠ Phone first (review 13 Oct 2026, ruling 14): the person who scanned the
 * tag is standing at the shelf with a thumb, so at phone width the custody
 * actions are a two-column grid of tall buttons; on a desk, the usual row.
 */
function Action({ href, primary, children }: { href: string; primary?: boolean; children: React.ReactNode }) {
    return (
        <Button variant={primary ? 'default' : 'outline'} className="h-11 sm:h-9" asChild>
            <Link href={href}>{children}</Link>
        </Button>
    );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

function PhotoUpload({ item, kind }: { item: Item; kind: string }) {
    const { setData, post, errors, processing, reset } = useForm<{ kind: string; photos: File[] }>({ kind, photos: [] });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/items/${item.id}/photos`, { forceFormData: true, preserveScroll: true, onSuccess: () => reset('photos') });
    };

    return (
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
    );
}

/**
 * One thing in the register (slice A1): its facts, its tag, its photos, its
 * parts and what has changed about it.
 *
 * ⚠ Reached by scanning its QR code too. Opening it changes nothing.
 */
export default function AssetItemShow({
    item,
    photos,
    history,
    movements,
    maintenance,
    can_manage,
    credentials,
    form,
    editing,
}: {
    item: Item;
    photos: PhotoGroup[];
    history: HistoryEntry[];
    movements: MovementRow[];
    maintenance: { plans: MaintenancePlanRow[]; records: MaintenanceRecordRow[] } | null;
    can_manage: boolean;
    credentials: LoginsCardData | null;
    // What the Edit popup needs; both null for someone who may only look.
    form: ThingFormOptions | null;
    editing: EditingThing | null;
}) {
    // ⭐ Edit is a popup over this page (13 Oct 2026), not a page of its own.
    const [editOpen, setEditOpen] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        { title: item.tag, href: `/assets/items/${item.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${item.tag} ${item.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-lg font-semibold break-words">{item.name}</h1>
                        <p className="text-muted-foreground font-mono text-sm">{item.tag}</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="secondary">{item.status_label}</Badge>
                            <Badge variant={item.condition === 'damaged' || item.condition === 'poor' ? 'destructive' : 'outline'}>
                                {item.condition_label}
                            </Badge>
                            {item.tracking === 'quantity' && <Badge variant="outline">{item.quantity} pieces</Badge>}
                            {item.outside?.overdue && <Badge variant="destructive">Overdue from {item.outside.party ?? 'outside'}</Badge>}
                        </div>
                    </div>
                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap">
                        <Action href={`/assets/items/labels?ids[]=${item.id}`}>Print tag</Action>
                        {can_manage && item.can_issue && (
                            <Action href={`/assets/movements/create?kind=issue&items[]=${item.id}`}>Hand to someone</Action>
                        )}
                        {can_manage && item.can_return && (
                            <Action href={`/assets/movements/create?kind=return&items[]=${item.id}`}>Record return</Action>
                        )}
                        {can_manage && item.can_reassign && (
                            <Action href={`/assets/movements/create?kind=reassign&items[]=${item.id}`}>Pass to someone else</Action>
                        )}
                        {can_manage && item.can_send_out && (
                            <Action href={`/assets/movements/create?kind=send_out&items[]=${item.id}`}>Send out</Action>
                        )}
                        {can_manage && item.can_receive_back && (
                            <Action href={`/assets/movements/create?kind=receive_back&items[]=${item.id}`}>Receive back</Action>
                        )}
                        {can_manage && item.can_transfer && (
                            <Action href={`/assets/movements/create?kind=transfer&items[]=${item.id}`}>Transfer</Action>
                        )}
                        {can_manage && form && editing && (
                            <Button className="h-11 sm:h-9" onClick={() => setEditOpen(true)}>
                                Edit
                            </Button>
                        )}
                    </div>
                </div>

                {/* ⚠ Slice A3: a count of this place is open. Marking is explicit;
                    opening this page by its QR code marked nothing. */}
                {item.open_count && can_manage && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-sky-500/40 bg-sky-500/5 p-3 text-sm">
                        <span>
                            Stock-take{' '}
                            <Link href={`/assets/counts/${item.open_count.id}`} className="underline underline-offset-2">
                                {item.open_count.number}
                            </Link>{' '}
                            is open here. {item.open_count.found === true && 'Marked found.'}
                            {item.open_count.found === false && 'Marked missing.'}
                        </span>
                        {item.tracking === 'individual' &&
                            (item.open_count.on_list ? (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/assets/counts/${item.open_count!.id}/mark`,
                                            { item_id: item.id, found: true },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Found here
                                </Button>
                            ) : (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(`/assets/counts/${item.open_count!.id}/found`, { tag: item.tag }, { preserveScroll: true })
                                    }
                                >
                                    Found here (not expected)
                                </Button>
                            ))}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_auto]">
                    <dl className="grid content-start gap-4 sm:grid-cols-2">
                        <Fact label="Category">{item.category ?? '-'}</Fact>
                        <Fact label="Counted">{item.tracking_label}</Fact>
                        <Fact label="Where it is">
                            {item.in_transit ? (
                                <Link href={`/assets/movements/${item.in_transit.id}`} className="underline underline-offset-2">
                                    On its way ({item.in_transit.number})
                                </Link>
                            ) : (
                                item.location
                            )}
                        </Fact>
                        <Fact label="Who has it">
                            {item.outside ? (
                                <>
                                    With {item.outside.party ?? item.custody} since {item.outside.since}
                                    {item.outside.expected_back_on && (
                                        <span className={item.outside.overdue ? 'text-destructive font-medium' : ''}>
                                            , expected back {item.outside.expected_back_on}
                                            {item.outside.overdue && ' (overdue)'}
                                        </span>
                                    )}{' '}
                                    <Link
                                        href={`/assets/movements/${item.outside.movement.id}`}
                                        className="text-muted-foreground underline underline-offset-2"
                                    >
                                        {item.outside.movement.number}
                                    </Link>
                                </>
                            ) : (
                                <>
                                    {item.holdings.length > 0 ? item.holdings.map((h) => `${h.holder}: ${h.quantity}`).join(', ') : item.custody}
                                    {item.custody_since && ` since ${item.custody_since}`}
                                </>
                            )}
                            {item.can_return && (
                                <span className={item.acknowledged_at ? 'text-muted-foreground' : 'text-amber-600 dark:text-amber-500'}>
                                    {item.acknowledged_at ? ` (acknowledged ${item.acknowledged_at})` : ' (not yet acknowledged)'}
                                </span>
                            )}
                        </Fact>
                        <Fact label="Brand and model">{[item.brand, item.model].filter(Boolean).join(' ') || '-'}</Fact>
                        <Fact label="Serial number">{item.serial_number ?? '-'}</Fact>
                        <Fact label="Bought">
                            {item.acquired_on ?? 'Date not recorded'}
                            {item.supplier && ` from ${item.supplier}`}
                        </Fact>
                        {item.purchase_price !== null && <Fact label="Price paid">{item.purchase_price}</Fact>}
                        <Fact label="Warranty">
                            {item.warranty_ends_on ? (
                                <span className={item.warranty_expired ? 'text-muted-foreground' : ''}>
                                    {item.warranty_expired ? `Ended ${item.warranty_ends_on}` : `Until ${item.warranty_ends_on}`}
                                </span>
                            ) : (
                                '-'
                            )}
                        </Fact>
                        {(item.service_ends_on || item.service_party) && (
                            <Fact label="Service contract">
                                <span className={item.service_expired ? 'text-muted-foreground' : ''}>
                                    {item.service_ends_on
                                        ? item.service_expired
                                            ? `Ended ${item.service_ends_on}`
                                            : `Until ${item.service_ends_on}`
                                        : 'No end date'}
                                    {item.service_party && ` with ${item.service_party}`}
                                </span>
                            </Fact>
                        )}
                        {item.parent && (
                            <Fact label="Part of">
                                <Link href={`/assets/items/${item.parent.id}`} className="underline underline-offset-2">
                                    {item.parent.tag} {item.parent.name}
                                </Link>
                            </Fact>
                        )}
                        <Fact label="Registered">
                            {item.registered_at}
                            {item.registered_by && ` by ${item.registered_by}`}
                        </Fact>
                        {item.specification && (
                            <div className="sm:col-span-2">
                                <Fact label="Specification">
                                    <span className="whitespace-pre-line">{item.specification}</span>
                                </Fact>
                            </div>
                        )}
                        {item.note && (
                            <div className="sm:col-span-2">
                                <Fact label="Note">
                                    <span className="whitespace-pre-line">{item.note}</span>
                                </Fact>
                            </div>
                        )}
                    </dl>

                    {/* ⚠ The code holds the item's permanent address and nothing else. */}
                    <div className="grid content-start justify-items-center gap-1 rounded-lg border p-3">
                        {/* ⚠ The SVG carries its own 240px size: scaled to the box, or it
                            sticks out and the page scrolls sideways (found on the walk). */}
                        <div className="size-40 bg-white p-1 [&>svg]:h-full [&>svg]:w-full" dangerouslySetInnerHTML={{ __html: item.qr_svg }} />
                        <p className="font-mono text-xs">{item.tag}</p>
                    </div>
                </div>

                {item.parts.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">Its parts</h2>
                        <ul className="grid gap-1 text-sm">
                            {item.parts.map((part) => (
                                <li key={part.id}>
                                    <Link href={`/assets/items/${part.id}`} className="underline underline-offset-2">
                                        {part.tag} {part.name}
                                    </Link>
                                    <span className="text-muted-foreground"> - {part.status_label}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/* Slice 3: its logins, for the holder and whoever manages secrets. */}
                <LoginsCard data={credentials} storeUrl={`/assets/items/${item.id}/credentials`} />

                <section className="grid gap-4">
                    <h2 className="font-medium">Photos</h2>
                    {photos.map((group) => (
                        <div key={group.kind} className="grid gap-2">
                            <p className="text-sm">{group.label}</p>
                            {group.files.length === 0 ? (
                                <p className="text-muted-foreground text-sm">None yet.</p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {group.files.map((file) => (
                                        <div key={file.id} className="grid gap-1">
                                            <a href={file.url} target="_blank" rel="noreferrer">
                                                <img src={file.url} alt={file.name} className="size-28 rounded-md border object-cover" />
                                            </a>
                                            {can_manage && group.uploadable && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button size="sm" variant="ghost" className="h-7">
                                                            Remove
                                                        </Button>
                                                    }
                                                    title="Remove this photo?"
                                                    description="The file is deleted."
                                                    confirmLabel="Remove"
                                                    destructive
                                                    onConfirm={() =>
                                                        router.delete(`/assets/items/${item.id}/photos/${file.id}`, { preserveScroll: true })
                                                    }
                                                />
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                            {can_manage && group.uploadable && <PhotoUpload item={item} kind={group.kind} />}
                        </div>
                    ))}
                </section>

                {/* Slice A4: what should be done to it, and what was. */}
                {maintenance && (
                    <section className="grid gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-medium">Maintenance</h2>
                            {can_manage && (
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" asChild>
                                        <Link href={`/assets/maintenance/plans/create?item=${item.id}`}>Plan care</Link>
                                    </Button>
                                    <Button size="sm" variant="outline" asChild>
                                        <Link href={`/assets/maintenance/create?item=${item.id}`}>Record work or a fault</Link>
                                    </Button>
                                </div>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <p className="text-sm">Plans</p>
                            <PlanList plans={maintenance.plans} canManage={can_manage} />
                        </div>
                        <div className="grid gap-2">
                            <p className="text-sm">Records</p>
                            <RecordList records={maintenance.records} />
                        </div>
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">Hand-overs</h2>
                    {movements.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet.</p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {movements.map((m) => (
                                <li key={m.id}>
                                    <span className="text-muted-foreground">{m.moved_on}</span>{' '}
                                    <Link href={`/assets/movements/${m.id}`} className="underline underline-offset-2">
                                        {m.number}
                                    </Link>{' '}
                                    {m.kind_label}: {m.from} to {m.to}
                                    {m.quantity > 1 && ` (${m.quantity})`}
                                    {m.status_label !== 'Done' && <span className="text-amber-600 dark:text-amber-500"> - {m.status_label}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">What has changed</h2>
                    {history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing yet.</p>
                    ) : (
                        <ul className="grid gap-2 text-sm">
                            {history.map((entry) => (
                                <li key={entry.id} className="grid gap-0.5">
                                    <p>
                                        <span className="text-muted-foreground">{entry.when}</span> {entry.who ?? 'The system'}: {entry.what}
                                    </p>
                                    {entry.changes.map((change) => (
                                        <p key={change.field} className="text-muted-foreground pl-3 text-xs">
                                            {change.field}: {change.from ?? '-'} to {change.to ?? '-'}
                                        </p>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {can_manage && form && editing && <ThingDialog open={editOpen} onOpenChange={setEditOpen} item={editing} options={form} />}
            </div>
        </AppLayout>
    );
}
