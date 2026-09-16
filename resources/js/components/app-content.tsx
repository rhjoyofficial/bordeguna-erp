import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import * as React from 'react';

interface AppContentProps extends React.ComponentProps<'div'> {
    variant?: 'header' | 'sidebar';
}

export function AppContent({ variant = 'header', children, className, ...props }: AppContentProps) {
    if (variant === 'sidebar') {
        // ⚠ min-w-0: the content column is a flex item beside the sidebar, and a
        // flex item will not shrink below its widest unbreakable child. The
        // self-service tabs (595px) held every My ... page at 642px on a 390px
        // phone, so their own overflow-x-auto never engaged. With min-w-0 the
        // column is the screen's width and wide children scroll inside
        // themselves. Measured 16 Sep 2026 across every page at 390 and 1280px.
        return (
            <SidebarInset className={cn('min-w-0', className)} {...props}>
                {children}
            </SidebarInset>
        );
    }

    return (
        <main className={cn('mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl', className)} {...props}>
            {children}
        </main>
    );
}
