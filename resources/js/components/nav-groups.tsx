import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';

export type NavGroup = {
    label: string;
    items: NavItem[];
    // ⚠ Settings is long and is opened rarely. It starts closed so it does not
    // push the working modules off the bottom of the sidebar; the moment
    // somebody is on a settings page it opens itself, like any other group.
    defaultOpen?: boolean;
    // ⚠ Listed for the header search's "Menus" group and never drawn: the
    // Settings screens, which the sidebar shows as one pinned link (15 Oct 2026).
    searchOnly?: boolean;
    // Drawn as plain top links with no heading and no fold (the Workspace
    // plan's Dashboard and All businesses, round 3). Always open.
    hideLabel?: boolean;
};

// The active item is the one whose url is the longest prefix of the current
// url. This keeps a section-overview item (e.g. /inventory) from lighting up
// on every child route (/inventory/products) - the more specific item wins.
function useActiveUrl(groups: NavGroup[]): string | null {
    // Query string stripped before matching: the dashboard's chart range
    // pills navigate to /dashboard?range=1y, and "?range=1y" is not a
    // child path - without this the active pill vanishes on any filtered
    // list or ranged view.
    const current = usePage().url.split('?')[0];
    // An item owns its url and, for an HRM section, every tab's prefix; the
    // longest prefix matched by ANY item wins, as before.
    // ⚠ On an EQUAL prefix the earlier item wins (the sort is stable), so the
    // never-drawn search groups stay LAST in useNavGroups: a tab listed there
    // must not take the highlight from the drawn section that owns it.
    const matches = groups
        .flatMap((group) => group.items)
        .flatMap((item) => [item.url, ...(item.match ?? [])].map((prefix) => ({ item, prefix })))
        .filter(({ prefix }) => current === prefix || current.startsWith(prefix + '/'))
        .sort((a, b) => b.prefix.length - a.prefix.length);

    return matches[0]?.item.url ?? null;
}

function NavGroupItems({ group, activeUrl }: { group: NavGroup; activeUrl: string | null }) {
    return (
        <SidebarMenu>
            {group.items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton asChild isActive={item.url === activeUrl} tooltip={{ children: item.title }}>
                        <Link href={item.url} prefetch>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                            {!!item.badge && (
                                <span className="bg-primary text-primary-foreground ml-auto flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs font-medium">
                                    {item.badge}
                                </span>
                            )}
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );
}

/** A group with no heading: its links sit at the top level, never folded away. */
function HeadlessNavGroup({ group, activeUrl }: { group: NavGroup; activeUrl: string | null }) {
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupContent>
                <NavGroupItems group={group} activeUrl={activeUrl} />
            </SidebarGroupContent>
        </SidebarGroup>
    );
}

function NavGroupSection({ group, activeUrl }: { group: NavGroup; activeUrl: string | null }) {
    const { state } = useSidebar();
    const isCollapsed = state === 'collapsed';
    const hasActive = group.items.some((item) => item.url === activeUrl);
    const storageKey = `sidebar:group:${group.label}`;

    const [open, setOpen] = useState<boolean>(() => {
        const fallback = group.defaultOpen ?? true;
        if (typeof window === 'undefined') return fallback;
        const stored = window.localStorage.getItem(storageKey);
        return stored === null ? fallback : stored === '1';
    });

    // Always reveal the group that owns the active route, even if the user had
    // previously collapsed it.
    useEffect(() => {
        if (hasActive) {
            setOpen(true);
        }
    }, [hasActive]);

    const handleOpenChange = (next: boolean) => {
        setOpen(next);
        window.localStorage.setItem(storageKey, next ? '1' : '0');
    };

    return (
        // In icon mode the section labels/triggers are hidden, so force the
        // items open there - otherwise a collapsed group would vanish entirely.
        <Collapsible open={isCollapsed ? true : open} onOpenChange={handleOpenChange} className="group/nav-section">
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel asChild>
                    <CollapsibleTrigger className="hover:text-sidebar-foreground flex w-full items-center justify-between gap-2">
                        <span>{group.label}</span>
                        <ChevronRight className="size-3.5 shrink-0 opacity-60 transition-transform duration-200 group-data-[state=open]/nav-section:rotate-90" />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <SidebarGroupContent>
                        <NavGroupItems group={group} activeUrl={activeUrl} />
                    </SidebarGroupContent>
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}

export function NavGroups({ groups }: { groups: NavGroup[] }) {
    const activeUrl = useActiveUrl(groups);

    // A group whose every row is gated away is not drawn: a cashier who may
    // sell but not price would otherwise see an empty "Sales setup" heading.
    return (
        <>
            {groups
                .filter((group) => group.items.length > 0 && !group.searchOnly)
                .map((group) =>
                    group.hideLabel ? (
                        <HeadlessNavGroup key={group.label} group={group} activeUrl={activeUrl} />
                    ) : (
                        <NavGroupSection key={group.label} group={group} activeUrl={activeUrl} />
                    ),
                )}
        </>
    );
}
