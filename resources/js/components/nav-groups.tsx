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
};

// The active item is the one whose url is the longest prefix of the current
// url. This keeps a section-overview item (e.g. /inventory) from lighting up
// on every child route (/inventory/products) — the more specific item wins.
function useActiveUrl(groups: NavGroup[]): string | null {
    const current = usePage().url;
    const matches = groups
        .flatMap((group) => group.items)
        .filter((item) => current === item.url || current.startsWith(item.url + '/'))
        .sort((a, b) => b.url.length - a.url.length);

    return matches[0]?.url ?? null;
}

function NavGroupSection({ group, activeUrl }: { group: NavGroup; activeUrl: string | null }) {
    const { state } = useSidebar();
    const isCollapsed = state === 'collapsed';
    const hasActive = group.items.some((item) => item.url === activeUrl);
    const storageKey = `sidebar:group:${group.label}`;

    const [open, setOpen] = useState<boolean>(() => {
        if (typeof window === 'undefined') return true;
        const stored = window.localStorage.getItem(storageKey);
        return stored === null ? true : stored === '1';
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
        // items open there — otherwise a collapsed group would vanish entirely.
        <Collapsible open={isCollapsed ? true : open} onOpenChange={handleOpenChange} className="group/nav-section">
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel asChild>
                    <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 hover:text-sidebar-foreground">
                        <span>{group.label}</span>
                        <ChevronRight className="size-3.5 shrink-0 opacity-60 transition-transform duration-200 group-data-[state=open]/nav-section:rotate-90" />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton asChild isActive={item.url === activeUrl} tooltip={{ children: item.title }}>
                                        <Link href={item.url} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}

export function NavGroups({ groups }: { groups: NavGroup[] }) {
    const activeUrl = useActiveUrl(groups);

    return (
        <>
            {groups.map((group) => (
                <NavGroupSection key={group.label} group={group} activeUrl={activeUrl} />
            ))}
        </>
    );
}
