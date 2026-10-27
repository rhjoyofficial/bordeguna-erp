import { Pagination } from '@/components/data-table/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { datesInText } from '@/lib/dates';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlarmClock, ArrowRight, Bell, CheckSquare, FileWarning, Receipt, Users, Wallet, type LucideIcon } from 'lucide-react';

interface NotificationRow {
    id: string;
    summary: string;
    url: string | null;
    kind: string;
    at: string | null;
    on: string | null;
    read: boolean;
}

interface NotificationsIndexProps {
    notifications: Paginated<NotificationRow>;
    filters: { state: string; kind: string | null };
    kinds: string[];
    counts: { all: number; unread: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/notifications' }];

/**
 * The notification inbox.
 *
 * ⚠⚠ WHY IT EXISTS (Joy, 6 Sep 2026). The bell renders five. Production was
 * holding 112 notifications, 99 of them unread, so ninety-four were reachable
 * from nowhere in the application - and since Joy switched approval emails off
 * on 23 Aug, the bell is the ONLY delivery mechanism there is. A message you
 * cannot open twice is a message that was never really delivered.
 *
 * ⚠ Read ones are kept and shown, not hidden. "I know I was told, I cannot
 * remember what it said" is the whole reason a person opens this page.
 */

const ICONS: Record<string, LucideIcon> = {
    approval: CheckSquare,
    lateness: AlarmClock,
    'follow-up': Users,
    expense: Receipt,
    'show-cause': FileWarning,
    pay: Wallet,
};

/** Turns `show-cause` into `Show cause` for the filter buttons. */
function label(kind: string): string {
    const words = kind.replace(/[-_]/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export default function NotificationsIndex({ notifications, filters, kinds, counts }: NotificationsIndexProps) {
    const setFilter = (next: Partial<{ state: string; kind: string | null }>) => {
        const merged = { ...filters, ...next };
        router.get(
            '/notifications',
            {
                state: merged.state,
                ...(merged.kind ? { kind: merged.kind } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Notifications</h1>
                        <p className="text-muted-foreground text-sm">
                            {counts.unread} unread of {counts.all}
                        </p>
                    </div>
                    {counts.unread > 0 && (
                        <Button variant="outline" size="sm" onClick={() => router.post('/notifications/read', {}, { preserveScroll: true })}>
                            Mark all read
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-1">
                    {(['unread', 'all', 'read'] as const).map((state) => (
                        <Button key={state} variant={filters.state === state ? 'default' : 'outline'} size="sm" onClick={() => setFilter({ state })}>
                            {label(state)}
                            {state === 'unread' && counts.unread > 0 ? ` (${counts.unread})` : ''}
                        </Button>
                    ))}

                    {kinds.length > 1 && (
                        <>
                            <span className="text-muted-foreground mx-1 text-xs">type</span>
                            <Button variant={!filters.kind ? 'default' : 'outline'} size="sm" onClick={() => setFilter({ kind: null })}>
                                Any
                            </Button>
                            {kinds.map((kind) => (
                                <Button
                                    key={kind}
                                    variant={filters.kind === kind ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setFilter({ kind })}
                                >
                                    {label(kind)}
                                </Button>
                            ))}
                        </>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card divide-y rounded-xl border">
                    {notifications.data.length === 0 && <p className="text-muted-foreground p-6 text-sm">Nothing here.</p>}

                    {notifications.data.map((n) => {
                        const Icon = ICONS[n.kind] ?? Bell;

                        return (
                            <div key={n.id} className="flex items-start gap-3 p-3">
                                <span
                                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                        n.read ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary'
                                    }`}
                                >
                                    <Icon className="h-4 w-4" />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className={`text-sm ${n.read ? '' : 'font-medium'}`}>{datesInText(n.summary)}</p>
                                        {!n.read && <Badge variant="outline">new</Badge>}
                                    </div>
                                    <p className="text-muted-foreground mt-0.5 text-xs">
                                        {n.on}
                                        {n.at ? ` · ${n.at}` : ''}
                                    </p>
                                </div>

                                <div className="flex shrink-0 items-center gap-1">
                                    {n.url && (
                                        <Link
                                            href={n.url}
                                            onClick={() => {
                                                if (!n.read) {
                                                    router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true });
                                                }
                                            }}
                                            className="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                                        >
                                            Open <ArrowRight className="h-3 w-3" />
                                        </Link>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(`/notifications/${n.id}/${n.read ? 'unread' : 'read'}`, {}, { preserveScroll: true })
                                        }
                                    >
                                        {n.read ? 'Mark unread' : 'Mark read'}
                                    </Button>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <Pagination meta={notifications} />
            </div>
        </AppLayout>
    );
}
