import { ConfirmDialog } from '@/components/confirm-dialog';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Entry {
    type: string;
    id: number;
    label: string;
    deleted_at: string | null;
    deleted_by: string | null;
    reason: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Recycle bin', href: '/settings/recycle-bin' },
];

/**
 * The Recycle bin (owner's answer 1, 17 Oct 2026). Deleted drafts and
 * never-used records stay here, hidden, for good. Only the Owner sees this
 * page and only the Owner restores. There is no "Delete forever".
 */
export default function RecycleBin({ entries }: { entries: Entry[]; modules: string[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recycle bin" />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <ReportHeading title="Recycle bin" />
                <p className="text-muted-foreground text-sm">
                    Deleted drafts and never-used records are kept here, hidden. Only the Owner can restore one. Nothing is ever deleted for good.
                </p>

                {entries.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border p-6 text-center text-sm">The Recycle bin is empty.</p>
                ) : (
                    <div className="grid gap-2">
                        {entries.map((entry) => (
                            <div
                                key={`${entry.type}-${entry.id}`}
                                className="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3 text-sm"
                            >
                                <div className="grid gap-1">
                                    <span className="font-medium">{entry.label}</span>
                                    <span className="text-muted-foreground text-xs">
                                        Deleted {showDate(entry.deleted_at) || ''}
                                        {entry.deleted_by ? ` by ${entry.deleted_by}` : ''}
                                        {entry.reason ? `: ${entry.reason}` : ''}
                                    </span>
                                </div>
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            Restore
                                        </Button>
                                    }
                                    title={`Restore ${entry.label}?`}
                                    description="It comes back exactly as it was, and the restore is recorded with your reason."
                                    confirmLabel="Restore"
                                    withNote
                                    noteRequired
                                    noteLabel="Why"
                                    onConfirm={(note) =>
                                        router.post(
                                            `/settings/recycle-bin/${entry.type}/${entry.id}/restore`,
                                            { reason_text: note },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
