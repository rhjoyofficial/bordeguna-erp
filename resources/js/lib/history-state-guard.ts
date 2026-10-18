/**
 * ⚠⚠ P8 (18 Oct 2026, practice run): "Record and confirm run" confirmed the run
 * on the server, then the page showed nothing and the console said
 * `TypeError: Cannot read properties of null (reading 'page')`. The operator
 * pressed again and made two drafts.
 *
 * THE CAUSE IS INSIDE INERTIA 2.0.3, NOT THE FORM. Inertia saves the scroll
 * position into `window.history.state.page` on every scroll, without checking
 * that a state exists. A tab opened fresh (typed address, new tab, a reload
 * that lands on a new entry) has `history.state === null` until Inertia writes
 * its first page, which waits for the page code to load. A scroll in that gap
 * throws inside Inertia's history queue, and that queue never recovers from a
 * rejected step: every later history write waits behind it, and Inertia swaps
 * the screen only after its history write. So the next visit (here the
 * redirect to the confirmed run) is fetched, but never shown.
 *
 * The guard: give a fresh entry Inertia's own first page as its state before
 * Inertia starts, so the scroll save always has a `page` to read. Inertia then
 * overwrites it with the same page as usual. Not encrypted history: this app
 * does not turn it on (config/inertia.php), so the plain page is what Inertia
 * would write itself.
 */
export interface HistoryLike {
    readonly state: unknown;
    replaceState(data: unknown, unused: string, url?: string | null): void;
}

/** True when the entry was seeded (it had no state); false when left alone. */
export function seedHistoryState(history: HistoryLike, initialPage: unknown, url: string): boolean {
    if (history.state !== null && history.state !== undefined) {
        return false;
    }

    if (initialPage === null || initialPage === undefined) {
        return false;
    }

    history.replaceState({ page: initialPage }, '', url);

    return true;
}

/** Reads the first page from the root element the server rendered (`data-page`). */
export function installHistoryStateGuard(): void {
    if (typeof window === 'undefined') return;

    try {
        const raw = document.getElementById('app')?.dataset.page;
        if (!raw) return;
        seedHistoryState(window.history, JSON.parse(raw), window.location.href);
    } catch {
        // A guard must never be the thing that breaks the boot.
    }
}
