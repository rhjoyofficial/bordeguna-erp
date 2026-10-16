import { LoginsCard, type LoginsCardData } from '@/components/assets/logins-card';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface MyItem {
    id: number;
    tag: string;
    name: string;
    category: string | null;
    business: string | null;
    since: string | null;
    condition: string;
    acknowledged_at: string | null;
    open_report: { number: string; reported_on: string } | null;
    credentials: LoginsCardData | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My profile', href: '/hrm/my-profile' },
    { title: 'My assets', href: '/hrm/my-assets' },
];

/**
 * Say what is wrong with a thing I hold (slice A4). It reaches the keeper as
 * an open repair record; nothing about the thing changes by itself.
 */
function Report({ item, onDone }: { item: MyItem; onDone: () => void }) {
    const { data, setData, post, errors, processing } = useForm<{ problem: string; photos: File[] }>({ problem: '', photos: [] });
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/hrm/my-assets/${item.id}/report`, { forceFormData: true, preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid w-full gap-2">
            <textarea
                className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                value={data.problem}
                onChange={(e) => setData('problem', e.target.value)}
                placeholder="What is wrong with it?"
                aria-label="What is wrong with it"
                aria-required
            />
            <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="w-full min-w-0 text-sm"
                onChange={(e) => setData('photos', Array.from(e.target.files ?? []).slice(0, 3))}
                aria-label="Photos"
            />
            <InputError message={errors.problem ?? fieldErrors.item ?? errors.photos ?? fieldErrors['photos.0']} />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={processing || !data.problem.trim()}>
                    Send to the keeper
                </Button>
                <Button size="sm" type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * What the signed-in employee holds (slice A2), "yes, I have it", and
 * "something is wrong with it" (slice A4). Returning a thing is recorded by
 * the keeper.
 */
export default function MyAssets({ items, pieces }: { items: MyItem[]; pieces: { tag: string | null; name: string | null; quantity: number }[] }) {
    const [reporting, setReporting] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My assets" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">Things I hold</h2>
                </div>

                {items.length === 0 && pieces.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">Nothing is recorded as with you.</p>
                ) : (
                    <ul className="grid gap-2">
                        {items.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium">{item.name}</p>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="font-mono">{item.tag}</span>
                                        {item.category && ` - ${item.category}`}
                                        {item.since && ` - since ${item.since}`}
                                        {item.business && ` - ${item.business}`}
                                    </p>
                                    {item.open_report && (
                                        <p className="text-xs text-amber-600 dark:text-amber-500">
                                            Problem reported {item.open_report.reported_on} ({item.open_report.number}), with the keeper
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {item.acknowledged_at ? (
                                        <Badge variant="secondary">Acknowledged {item.acknowledged_at}</Badge>
                                    ) : (
                                        <Button
                                            size="sm"
                                            onClick={() => router.post(`/hrm/my-assets/${item.id}/acknowledge`, {}, { preserveScroll: true })}
                                        >
                                            Yes, I have it
                                        </Button>
                                    )}
                                    {!item.open_report && reporting !== item.id && (
                                        <Button size="sm" variant="outline" onClick={() => setReporting(item.id)}>
                                            Report a problem
                                        </Button>
                                    )}
                                </div>
                                {reporting === item.id && <Report item={item} onDone={() => setReporting(null)} />}
                                {/* Slice 3: its logins, for the holder (the laptop password to use the laptop). */}
                                {item.credentials && (item.credentials.rows.length > 0 || item.credentials.can_manage) && (
                                    <div className="w-full border-t pt-3">
                                        <LoginsCard data={item.credentials} storeUrl={`/assets/items/${item.id}/credentials`} title="Its logins" />
                                    </div>
                                )}
                            </li>
                        ))}
                        {pieces.map((piece, index) => (
                            <li key={`p${index}`} className="rounded-lg border p-3 text-sm">
                                <p className="font-medium">
                                    {piece.name} x {piece.quantity}
                                </p>
                                <p className="text-muted-foreground font-mono text-xs">{piece.tag}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
