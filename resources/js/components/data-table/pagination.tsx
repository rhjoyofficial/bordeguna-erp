import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';
import { router } from '@inertiajs/react';

// Pagination footer driven by a Laravel paginator. The prev/next URLs already
// carry the active filters (controller uses ->withQueryString()), so we just
// visit them, preserving the typed filter state and scroll position.
export function Pagination<T>({ meta }: { meta: Paginated<T> }) {
    const go = (url: string | null) => {
        if (url) {
            router.get(url, {}, { preserveState: true, preserveScroll: true });
        }
    };

    if (meta.total === 0) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2">
            <p className="text-muted-foreground text-xs">
                Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
            </p>
            {meta.last_page > 1 && (
                <div className="flex items-center gap-2">
                    <span className="text-muted-foreground text-xs">
                        Page {meta.current_page} of {meta.last_page}
                    </span>
                    <Button variant="outline" size="sm" disabled={!meta.prev_page_url} onClick={() => go(meta.prev_page_url)}>
                        Previous
                    </Button>
                    <Button variant="outline" size="sm" disabled={!meta.next_page_url} onClick={() => go(meta.next_page_url)}>
                        Next
                    </Button>
                </div>
            )}
        </div>
    );
}
