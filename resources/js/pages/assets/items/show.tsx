import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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
    supplier: string | null;
    purchase_price: string | null;
    note: string | null;
    registered_by: string | null;
    registered_at: string | null;
    tag_url: string;
    qr_svg: string;
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
    can_manage,
}: {
    item: Item;
    photos: PhotoGroup[];
    history: HistoryEntry[];
    can_manage: boolean;
}) {
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
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/assets/items/labels?ids[]=${item.id}`}>Print tag</Link>
                        </Button>
                        {can_manage && (
                            <Button asChild>
                                <Link href={`/assets/items/${item.id}/edit`}>Edit</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[1fr_auto]">
                    <dl className="grid content-start gap-4 sm:grid-cols-2">
                        <Fact label="Category">{item.category ?? '-'}</Fact>
                        <Fact label="Counted">{item.tracking_label}</Fact>
                        <Fact label="Where it is">{item.location}</Fact>
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
            </div>
        </AppLayout>
    );
}
