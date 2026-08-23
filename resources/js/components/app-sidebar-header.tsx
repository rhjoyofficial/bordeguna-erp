import { Breadcrumbs } from '@/components/breadcrumbs';
import HeaderFullscreenToggle from '@/components/header-fullscreen-toggle';
import HeaderNotifications from '@/components/header-notifications';
import HeaderQuickCreate from '@/components/header-quick-create';
import HeaderSearch from '@/components/header-search';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="bg-topbar text-topbar-foreground border-topbar-border flex h-16 shrink-0 items-center justify-between gap-2 border-b px-6 transition-[width,height,background-color,color] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-1">
                <HeaderSearch />
                <HeaderQuickCreate />
                <HeaderNotifications />
                <HeaderFullscreenToggle />
            </div>
        </header>
    );
}
