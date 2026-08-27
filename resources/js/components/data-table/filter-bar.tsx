import { Button } from '@/components/ui/button';
import { X } from 'lucide-react';
import { type ReactNode } from 'react';

// Presentational container for list-page filter controls: a responsive row of
// inputs/selects with a Clear affordance that appears once any filter is set.
export function FilterBar({ children, onClear, showClear = false }: { children: ReactNode; onClear?: () => void; showClear?: boolean }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-wrap items-end gap-3 rounded-xl border p-3">
            {children}
            {showClear && onClear && (
                <Button type="button" variant="ghost" size="sm" onClick={onClear} className="text-muted-foreground">
                    <X className="mr-1 size-3.5" /> Clear
                </Button>
            )}
        </div>
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
