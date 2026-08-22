import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Bell, CheckSquare } from 'lucide-react';

/**
 * The header bell. Today it carries the one live signal the backend
 * already shares on every request - pending approvals - and is only
 * rendered for users who can act on them. Low-stock and import-result
 * lines can join later without changing this shape; they are not shown
 * now because faking a feed with placeholder rows would be the
 * empty-but-styled chart problem in a dropdown.
 */
export default function HeaderNotifications() {
    const { pendingApprovalsCount, canReviewApprovals } = usePage<SharedData>().props;

    if (!canReviewApprovals) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative" aria-label={`Notifications: ${pendingApprovalsCount} pending approvals`}>
                    <Bell className="h-4.5 w-4.5" />
                    {pendingApprovalsCount > 0 && (
                        <span className="bg-destructive text-destructive-foreground absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium">
                            {pendingApprovalsCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72 p-2">
                <p className="text-muted-foreground px-2 pt-1 pb-2 text-xs font-medium">Notifications</p>
                {pendingApprovalsCount > 0 ? (
                    <Link
                        href="/approvals"
                        className="hover:bg-accent hover:text-accent-foreground flex items-start gap-3 rounded-md p-2 transition-colors"
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
                ) : (
                    <p className="text-muted-foreground px-2 pb-2 text-sm">Nothing needs your attention.</p>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
