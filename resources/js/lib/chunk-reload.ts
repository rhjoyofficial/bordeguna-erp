/**
 * A page that cannot load its own code reloads once instead of staying white
 * (F34, practice run 17 Oct 2026: the first visit to /accounting/overview
 * showed a blank page that was fine after a reload).
 *
 * Every page is its own file, fetched when first opened. If that fetch fails
 * (a release swapped the files while the tab was open, or the network dropped
 * one request), Inertia never gets a component and nothing is drawn. One
 * reload fetches the current files and the page appears.
 *
 * ⚠ At most one reload in 30 seconds, remembered per tab, so a page that is
 * genuinely broken shows its error rather than reloading forever. Storage may
 * be refused (private windows): then we simply do not reload.
 */
const KEY = 'chunk-reload.at';
const WINDOW_MS = 30_000;

export function reloadOnceForMissingCode(): boolean {
    try {
        const last = Number(window.sessionStorage.getItem(KEY) ?? 0);

        if (Date.now() - last < WINDOW_MS) {
            return false;
        }

        window.sessionStorage.setItem(KEY, String(Date.now()));
    } catch {
        return false;
    }

    window.location.reload();

    return true;
}

/** Vite's own signal that a page's code could not be preloaded. */
export function installChunkReload(): void {
    window.addEventListener('vite:preloadError', (event) => {
        if (reloadOnceForMissingCode()) {
            event.preventDefault();
        }
    });
}
