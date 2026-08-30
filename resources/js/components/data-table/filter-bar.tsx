import { CompactControls } from '@/components/form/compact-controls';
import { Button } from '@/components/ui/button';
import { X } from 'lucide-react';
import { type ReactNode } from 'react';

// Presentational container for list-page filter controls: a responsive row of
// inputs/selects with a Clear affordance that appears once any filter is set.
//
// ⚠⚠ EVERY SELECT INSIDE IS CONTENT-WIDTH, by CompactControls. Without it a
// `w-full` dropdown inside this wrapping row claims the whole row, which is
// how six list pages ended up with one filter per line (30 Aug 2026 audit).
export function FilterBar({ children, onClear, showClear = false }: { children: ReactNode; onClear?: () => void; showClear?: boolean }) {
    return (
        <CompactControls>
            <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-wrap items-end gap-3 rounded-xl border p-3">
                {children}
                {showClear && onClear && (
                    <Button type="button" variant="ghost" size="sm" onClick={onClear} className="text-muted-foreground">
                        <X className="mr-1 size-3.5" /> Clear
                    </Button>
                )}
            </div>
        </CompactControls>
    );
}

// A single labelled filter field wrapper for consistent spacing/width.
export function FilterField({
    label,
    htmlFor,
    children,
    className = '',
}: {
    label: string;
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid gap-1.5 ${className}`}>
            <label htmlFor={htmlFor} className="text-muted-foreground text-xs font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}
