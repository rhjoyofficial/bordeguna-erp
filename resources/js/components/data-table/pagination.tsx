import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

/** ⭐ 31 Oct 2026 (Joy): the same footer on every list. Matches app/Support/PerPage.php. */
export const PER_PAGE_OPTIONS = [20, 50, 200, 500] as const;
const ALL_ROWS = 10000;

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

/** 1 … 4 5 6 … 12: the first, the last, and the pages either side of this one. */
function pageWindow(current: number, last: number): (number | 'gap')[] {
    const pages = new Set([1, last, current - 1, current, current + 1].filter((p) => p >= 1 && p <= last));
    // Near an end, show a few more instead of a lone gap.
    if (current <= 3) [2, 3, 4].forEach((p) => p <= last && pages.add(p));
    if (current >= last - 2) [last - 1, last - 2, last - 3].forEach((p) => p >= 1 && pages.add(p));

    const sorted = [...pages].sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];
    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) out.push('gap');
        out.push(page);
    });
    return out;
}

/**
 * Left: Showing [20] (1-20) of 59. Right: Previous 1 2 3 … Next.
 *
 * An Inertia list needs only `meta`: a page or a size change is a visit to the
 * same address with `page` / `per_page` set, every filter kept. A list fetched
 * by hand (a panel) passes `onChange` and loads the rows itself.
 */
export function Pagination({
    meta,
    pageName = 'page',
    onChange,
    className,
}: {
    meta: PageMeta;
    /** The query key of this list's page, when a screen has two lists. */
    pageName?: string;
    onChange?: (page: number, perPage: number | 'all') => void;
    className?: string;
}) {
    if (meta.total === 0) {
        return null;
    }

    const size: number | 'all' = meta.per_page >= ALL_ROWS ? 'all' : meta.per_page;

    const visit = (page: number, perPage: number | 'all') => {
        if (onChange) {
            onChange(page, perPage);
            return;
        }

        const url = new URL(window.location.href);
        url.searchParams.set(pageName, String(page));
        if (perPage === 20) url.searchParams.delete('per_page');
        else url.searchParams.set('per_page', String(perPage));
        if (page === 1) url.searchParams.delete(pageName);

        router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true });
    };

    const current = meta.current_page;
    const last = meta.last_page;
    const button = 'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md px-2 text-sm tabular-nums transition-colors';

    return (
        <div className={cn('flex flex-wrap items-center justify-between gap-3', className)}>
            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                <span>Showing</span>
                <select
                    aria-label="Rows per page"
                    value={String(size)}
                    onChange={(e) => visit(1, e.target.value === 'all' ? 'all' : Number(e.target.value))}
                    className="border-input bg-background text-foreground h-8 rounded-md border px-2 text-sm"
                >
                    {PER_PAGE_OPTIONS.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                    <option value="all">All</option>
                </select>
                <span className="tabular-nums">
                    ({meta.from ?? 0}-{meta.to ?? 0}) of {meta.total}
                </span>
            </div>

            {last > 1 && (
                <nav aria-label="Pages" className="flex items-center gap-1">
                    <button
                        type="button"
                        disabled={current <= 1}
                        onClick={() => visit(current - 1, size)}
                        className={cn(button, 'text-muted-foreground hover:text-foreground disabled:pointer-events-none disabled:opacity-40')}
                    >
                        <ChevronLeft className="size-4" aria-hidden="true" />
                        Previous
                    </button>
                    {pageWindow(current, last).map((page, index) =>
                        page === 'gap' ? (
                            <span key={`gap-${index}`} className="text-muted-foreground px-1">
                                …
                            </span>
                        ) : (
                            <button
                                key={page}
                                type="button"
                                aria-current={page === current ? 'page' : undefined}
                                onClick={() => page !== current && visit(page, size)}
                                className={cn(button, page === current ? 'bg-primary text-primary-foreground font-semibold' : 'hover:bg-muted')}
                            >
                                {page}
                            </button>
                        ),
                    )}
                    <button
                        type="button"
                        disabled={current >= last}
                        onClick={() => visit(current + 1, size)}
                        className={cn(button, 'text-muted-foreground hover:text-foreground disabled:pointer-events-none disabled:opacity-40')}
                    >
                        Next
                        <ChevronRight className="size-4" aria-hidden="true" />
                    </button>
                </nav>
            )}
        </div>
    );
}
