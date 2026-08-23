import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckSquare } from 'lucide-react';

/**
 * The header bell.
 *
 * ⚠⚠ THIS IS NOW THE ONLY WAY AN APPROVER LEARNS SOMETHING IS WAITING. Until
 * 2026-08-23 every approval also sent an email; Joy switched that off ("do not
 * send mail to request correction, ERP notification is enough"), so the bell
 * stopped being a convenience and became the delivery mechanism. That is why it
 * shows real stored notifications rather than only the live pending count.
 *
 * ⚠ Two different things share this dropdown on purpose. The pending approvals
 * line is a LIVE COUNT of work still outstanding, so it disappears when the
 * work is done by anyone. A notification is a stored EVENT addressed to this
 * user, and it stays until they read it. Collapsing them into one list would
 * mean either a count that lingers after the work is finished, or an event that
 * vanishes because a colleague acted.
 *
 * ⚠ Rendered for anyone with notifications, not only approvers. The old version
 * returned null unless the user could review approvals, which would now hide
 * every notification from everyone else.
 */
export default function HeaderNotifications() {
    const { pendingApprovalsCount, canReviewApprovals, unreadNotificationsCount, recentNotifications } =
        usePage<SharedData>().props;

    const notifications = recentNotifications ?? [];
    const unread = unreadNotificationsCount ?? 0;

    if (!canReviewApprovals && unread === 0) {
        return null;
    }

    // The badge counts unread notifications, falling back to the approvals
    // count so an approver who has read everything still sees outstanding work.
    const badge = unread > 0 ? unread : canReviewApprovals ? pendingApprovalsCount : 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={`Notifications: ${unread} unread, ${pendingApprovalsCount} pending approvals`}
                >
                    <Bell className="h-4.5 w-4.5" />
                    {badge > 0 && (
                        <span className="bg-destructive text-destructive-foreground absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium">
                            {badge}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-2">
                <div className="flex items-center justify-between px-2 pt-1 pb-2">
                    <p className="text-muted-foreground text-xs font-medium">Notifications</p>
                    {unread > 0 && (
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground text-xs underline-offset-2 hover:underline"
                            onClick={() => router.post('/notifications/read', {}, { preserveScroll: true })}
                        >
                            Mark all read
                        </button>
                    )}
                </div>

                {notifications.map((n) => (
                    <Link
                        key={n.id}
                        href={n.url ?? '/approvals'}
                        onClick={() => router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true })}
                        className="hover:bg-accent hover:text-accent-foreground flex items-start gap-3 rounded-md p-2 transition-colors"
                    >
                        <span className="bg-primary/10 text-primary flex h-8 w-8 shrink-0 items-center justify-center rounded-lg">
                            <CheckSquare className="h-4 w-4" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm">{n.summary}</span>
                            {n.at && <span className="text-muted-foreground block text-xs">{n.at}</span>}
                        </span>
                    </Link>
                ))}

                {canReviewApprovals && pendingApprovalsCount > 0 && (
                    <Link
                        href="/approvals"
                        className="hover:bg-accent hover:text-accent-foreground mt-1 flex items-start gap-3 rounded-md border-t p-2 pt-3 transition-colors"
                    >
                        <span className="bg-primary/10 text-primary flex h-8 w-8 shrink-0 items-center justify-center rounded-lg">
                            <CheckSquare className="h-4 w-4" />
                        </span>
                        <span className="text-sm">
                            {pendingApprovalsCount === 1
                                ? '1 request is waiting for approval'
                                : `${pendingApprovalsCount} requests are waiting for approval`}
                        </span>
                    </Link>
                )}

                {notifications.length === 0 && pendingApprovalsCount === 0 && (
                    <p className="text-muted-foreground px-2 pb-2 text-sm">Nothing needs your attention.</p>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
