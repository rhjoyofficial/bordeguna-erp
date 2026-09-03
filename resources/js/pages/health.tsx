import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The Business Health feed (Module 7, Slice 1): everything that needs a
 * human, computed fresh from the module that owns it, each item linking to
 * where the fix lives. Green means green.
 */
interface Signal {
    key: string;
    label: string;
    count: number;
    url: string;
    hint: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business health', href: '/health' }];

export default function Health({ signals }: { signals: Signal[] }) {
    const attention = signals.filter((signal) => signal.count > 0);
    const calm = signals.filter((signal) => signal.count === 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business health" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Business health</h1>
                    <p className="text-muted-foreground text-sm">
                        The morning walk around the shop, done by the system. Every number is read fresh from its own module and links to
                        where the fix lives - nothing here is stored, so nothing here can go stale.
                    </p>
                </div>

                {attention.length === 0 ? (
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-6 text-center">
                        <p className="text-lg font-semibold text-emerald-700">All clear.</p>
                        <p className="text-muted-foreground text-sm">Nothing is waiting on anyone right now.</p>
                    </div>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {attention.map((signal) => (
                            <Link
                                key={signal.key}
                                href={signal.url}
                                className="block rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 transition hover:border-amber-500"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <p className="font-medium">{signal.label}</p>
                                    <span className="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-lg font-bold text-amber-700 tabular-nums">
                                        {signal.count}
                                    </span>
                                </div>
                                <p className="text-muted-foreground mt-1 text-sm">{signal.hint}</p>
                            </Link>
                        ))}
                    </div>
                )}

                {calm.length > 0 && (
                    <div className="space-y-1">
                        <h2 className="text-muted-foreground text-sm font-medium">Quiet fronts</h2>
                        <div className="flex flex-wrap gap-2">
                            {calm.map((signal) => (
                                <span key={signal.key} className="text-muted-foreground rounded-full border px-3 py-1 text-xs">
                                    {signal.label}: 0
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
