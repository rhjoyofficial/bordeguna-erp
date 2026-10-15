import HeadingSmall from '@/components/heading-small';
import { ReasonDialog } from '@/components/settings/reason-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Reasons', href: '/settings/reasons' },
];

function ReasonRow({ reason, onRename }: { reason: Reason; onRename: () => void }) {
    const toggleActive = () => {
        router.patch(route('reasons.toggle-active', reason.id));
    };

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
                <Button variant="ghost" size="sm" onClick={onRename}>
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
    // ⭐ Add and Rename open one small popup (Joy, 14 Oct 2026: "click add button
    // pop up will add, same when edit"). `undefined` = closed; `reason: null` = a
    // new reason for that list.
    const [dialog, setDialog] = useState<{ context: string; reason: Reason | null } | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reasons" />

            <SettingsLayout>
                <div className="space-y-10">
                    <HeadingSmall title="Reasons" />

                    {Object.entries(contexts).map(([context, label]) => {
                        const contextReasons = reasons.filter((reason) => reason.context === context);

                        return (
                            <div key={context} className="space-y-4">
                                {/* No permission check of its own: the whole page needs the
                                    same one as saving (inventory.catalog.manage). */}
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h3 className="text-sm font-medium">{label}</h3>
                                    <Button size="sm" onClick={() => setDialog({ context, reason: null })}>
                                        Add reason
                                    </Button>
                                </div>

                                <div>
                                    {contextReasons.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">No reasons yet.</p>
                                    ) : (
                                        contextReasons.map((reason) => (
                                            <ReasonRow key={reason.id} reason={reason} onRename={() => setDialog({ context, reason })} />
                                        ))
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>

                <ReasonDialog
                    open={dialog !== undefined}
                    onOpenChange={(open) => !open && setDialog(undefined)}
                    context={dialog?.context ?? ''}
                    contextLabel={dialog ? (contexts[dialog.context] ?? '') : ''}
                    reason={dialog?.reason ?? null}
                />
            </SettingsLayout>
        </AppLayout>
    );
}
