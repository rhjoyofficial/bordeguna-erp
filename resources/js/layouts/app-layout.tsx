import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { useNoEnterSubmit } from '@/lib/no-enter-submit';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function AppLayout({ children, breadcrumbs, ...props }: AppLayoutProps) {
    // ⛔ No keyboard shortcuts (Joy, 19 Oct 2026): Enter never saves a form.
    useNoEnterSubmit();

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
            {children}
        </AppLayoutTemplate>
    );
}
