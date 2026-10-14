import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import ErpAssistant from '@/components/erp-assistant';
import { LivePulse } from '@/components/live-pulse';
import { PopupHost } from '@/components/popup-host';
import { Toaster } from '@/components/ui/sonner';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useLiveReload } from '@/hooks/use-live-reload';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    useFlashToast();
    // Realtime everywhere: the page refreshes itself when what it shows changes.
    useLiveReload();

    const { canUseAssistant } = usePage<SharedData>().props;

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
            <Toaster />
            <LivePulse />
            {/* The popups from Settings > Popups (Bismillah among them): on sign-in and on return
                after their idle time; off per person in Settings > Appearance. */}
            <PopupHost />
            {/* ⚠ Switched on per business by an admin, and then only for people
                holding foundation.ai.use. Everyone else gets no button at all. */}
            {canUseAssistant && <ErpAssistant />}
        </AppShell>
    );
}
