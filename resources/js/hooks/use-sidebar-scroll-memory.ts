import { useCallback, useLayoutEffect, useRef } from 'react';

/**
 * Keeps the sidebar where you left it when you click a link in it.
 *
 * ⚠⚠ WHY THIS IS NEEDED, because the cause is not obvious. This application
 * does not use Inertia's PERSISTENT LAYOUTS: every page component returns its
 * own `<AppLayout>`, so a visit unmounts the whole tree and mounts a new one.
 * That includes the sidebar, and a freshly mounted scroll container starts at
 * the top. So somebody who scrolled down to Payroll, clicked it, and wanted the
 * next payroll screen was thrown back to the top of the menu every single time.
 *
 * The proper Inertia answer is a persistent layout, but that means removing
 * `<AppLayout>` from every one of the ~50 page components and changing how each
 * one is written. That is a large, risky change to make for a scroll offset, and
 * it would alter remount behaviour for pages that currently rely on it. This
 * hook fixes the reported problem where it happens instead.
 *
 * ⚠ Restored in `useLayoutEffect`, deliberately, so the offset is applied BEFORE
 * the browser paints. In a plain `useEffect` the sidebar would paint at the top
 * and then jump, which looks worse than the bug being fixed.
 *
 * ⚠ The offset is held in a module variable AND in `sessionStorage`. The module
 * variable is what survives the remount on every visit and costs nothing to
 * read. `sessionStorage` is what survives a full page reload, which happens on
 * logout, on a 419, and whenever somebody presses F5. Storage access is wrapped
 * because it throws outright in a browser configured to block site data.
 */
const STORAGE_KEY = 'erp.sidebar.scroll';

let lastOffset: number | null = null;

function readOffset(): number {
    if (lastOffset !== null) {
        return lastOffset;
    }

    try {
        const stored = Number(window.sessionStorage.getItem(STORAGE_KEY));

        lastOffset = Number.isFinite(stored) && stored > 0 ? stored : 0;
    } catch {
        lastOffset = 0;
    }

    return lastOffset;
}

function writeOffset(offset: number): void {
    lastOffset = offset;

    try {
        window.sessionStorage.setItem(STORAGE_KEY, String(offset));
    } catch {
        // A browser blocking site data still gets working navigation; it just
        // forgets the offset across a hard reload. Never worth an exception.
    }
}

export function useSidebarScrollMemory<T extends HTMLElement>() {
    const ref = useRef<T | null>(null);

    useLayoutEffect(() => {
        const element = ref.current;
        const offset = readOffset();

        if (element === null || offset <= 0) {
            return;
        }

        element.scrollTop = offset;

        // ⚠ One retry on the next frame, and it earns its place: icons and the
        // web font can still be resolving on the first layout pass, so the
        // container may be shorter than it is about to be and the browser
        // clamps the assignment to whatever fits. Without this the sidebar
        // lands near the right place on a warm load and near the top on a cold
        // one, which reads as an intermittent bug.
        const frame = requestAnimationFrame(() => {
            if (ref.current !== null && ref.current.scrollTop !== offset) {
                ref.current.scrollTop = offset;
            }
        });

        return () => cancelAnimationFrame(frame);
    }, []);

    const onScroll = useCallback((event: React.UIEvent<T>) => {
        writeOffset(event.currentTarget.scrollTop);
    }, []);

    return { ref, onScroll };
}
