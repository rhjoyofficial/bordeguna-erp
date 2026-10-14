import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { installAudioUnlock } from './lib/audio-unlock';
import { inFlightMutations, markWriteFinished, setPageState, socketCoversPage } from './lib/live-reload';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * W3 - the stale tab.
 *
 * The current business lives in the session, so it belongs to the browser and
 * not to the page. Switch business in another tab, come back to this one which
 * is still showing the previous tenant's figures, click a button, and the write
 * used to land in a tenant this page was never displaying.
 *
 * So every mutating request carries the business THIS TAB last rendered, and the
 * server refuses a mismatch. That value is right by construction: a tab that
 * never navigated still holds the business it was built for, which is exactly
 * what is on the screen in front of whoever is clicking.
 *
 * ⚠ Deliberately not axios. Inertia bundles it, but importing it here would tie
 * the app to a package `package.json` never names. `router.on('before')` is
 * Inertia's own hook and covers every visit, so no form can forget to opt in.
 */
let pageBusinessId: number | null = null;

/**
 * The double-submit guard (Joy, 28 Sep 2026: "sometimes they press one button
 * multiple time, then the request come multiple time ... until request complete,
 * resubmit not be possible").
 *
 * ⚠⚠ THE CAUSE IS A HUMAN ONE, NOT A SLOW SERVER. An approver taps Approve, sees
 * nothing move for a moment, taps again, and a leave, a payment or a stock
 * movement is written twice. The backend guards its own races (see
 * ApprovalRequest::assertStillPending), but a duplicate CREATE - two identical
 * requests raised by one impatient double-tap - has no such guard, so it is
 * stopped here, at the one hook every visit passes through.
 *
 * Only mutating methods are held: a second POST/PUT/PATCH/DELETE to the same URL
 * is dropped while the first is still in flight. GETs (navigation, the approval
 * inbox's own auto-refresh, downloads, reports) are never blocked - blocking a
 * read would break the interface for no gain. The key clears on `finish`, which
 * Inertia fires for a completed, failed OR cancelled visit, so a legitimate
 * retry after the first settles is always allowed.
 */
// The set itself lives in lib/live-reload.ts: the live refresh reads it too.

const mutationKey = (visit: { method: string; url: URL | string }): string | null => {
    const method = String(visit.method).toLowerCase();
    if (method === 'get') {
        return null;
    }

    return `${method} ${visit.url.toString()}`;
};

router.on('before', (event) => {
    lastVisit = { method: String(event.detail.visit.method).toLowerCase(), path: event.detail.visit.url.pathname };

    if (pageBusinessId !== null) {
        event.detail.visit.headers['X-Business-Id'] = String(pageBusinessId);
    }

    const key = mutationKey(event.detail.visit);
    if (key === null) {
        return;
    }

    if (inFlightMutations.has(key)) {
        // Returning false cancels the visit: the identical write is already on
        // its way, and this tap is the duplicate we exist to swallow.
        return false;
    }

    inFlightMutations.add(key);
});

router.on('finish', (event) => {
    const key = mutationKey(event.detail.visit);
    if (key !== null) {
        inFlightMutations.delete(key);
        markWriteFinished();
    }
});

/**
 * Whether the page on screen can be read again with a GET.
 *
 * ⚠⚠ An import preview is the RESPONSE to a POST (upload the file, show what it
 * would do) and its URL has no GET route. Auto-refresh re-read that URL, got a
 * 405, and dropped the user on the dashboard with the preview - and its Apply
 * button - gone (29 Sep 2026: every import screen was unusable). A page reached
 * by a write that did not redirect elsewhere is therefore never auto-refreshed.
 */
let pageIsReloadable = true;
// A page that refreshes itself (the role dashboards: socket plus per-widget
// polling) sends `auto_refresh: false` and the global reload stands down.
let pageOptsOut = false;
let lastVisit: { method: string; path: string } | null = null;

router.on('success', (event) => {
    pageBusinessId = (event.detail.page.props.current_business_id as number | null) ?? null;
    pageOptsOut = event.detail.page.props.auto_refresh === false;

    if (lastVisit !== null) {
        const landedOn = new URL(event.detail.page.url, window.location.origin).pathname;
        pageIsReloadable = lastVisit.method === 'get' || landedOn !== lastVisit.path;
    }

    setPageState({ reloadable: pageIsReloadable, optsOut: pageOptsOut, live: event.detail.page.props.live as string[] | undefined });
});

/**
 * Near-real-time without a manual refresh (Joy, 28 Sep 2026: "updating your user
 * interface instantly as new data arrives ... without requiring a manual page
 * refresh"). Every open page quietly re-reads itself on an interval, so a figure
 * someone else changed appears on its own.
 *
 * ⚠ router.reload preserves scroll AND local component state by default, so an
 * open dialog, a half-typed field and a useForm in progress all survive a tick
 * untouched - only the server props refresh underneath. Two guards keep it from
 * ever getting in the way: it does nothing while the tab is hidden (a background
 * tab has no reason to poll), and nothing while a write is in flight (never pull
 * the page out from under a submit - the inFlightMutations set the double-submit
 * guard already maintains tells us). Since 11 Oct 2026 the push channel (Laravel
 * Reverb, lib/live-reload.ts) makes a wired page instant, and this poll is only
 * its fallback while the socket is down, plus the refresh for pages not wired.
 *
 * ⚠ 30s: brisk enough that a shared screen feels live, slow enough that it is not
 * a load on a two-core box shared with other stacks.
 */
const AUTO_REFRESH_MS = 30000;

window.setInterval(() => {
    if (document.visibilityState !== 'visible') {
        return;
    }

    if (inFlightMutations.size > 0 || !pageIsReloadable || pageOptsOut) {
        return;
    }

    // Realtime everywhere (11 Oct 2026): a page that declared what it shows is
    // refreshed by the socket the moment something changes (lib/live-reload),
    // so this timer is only its fallback while the socket is down. A page that
    // declared nothing keeps polling.
    if (socketCoversPage()) {
        return;
    }

    router.reload();
}, AUTO_REFRESH_MS);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        // The first page is rendered without a visit, so `success` never fires
        // for it. Without this the very first action in a fresh tab would be
        // unstamped, and §13.6 fails those closed.
        pageBusinessId = (props.initialPage.props.current_business_id as number | null) ?? null;
        pageOptsOut = props.initialPage.props.auto_refresh === false;
        setPageState({ optsOut: pageOptsOut, live: props.initialPage.props.live as string[] | undefined });

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    /*
     * The only "preloader" this ERP has, and the right one for it.
     *
     * ⚠⚠ A FULL-SCREEN SPINNER WOULD BE WRONG HERE. Inertia delivers a page
     * complete with its data, so there is no half-loaded screen to cover and
     * nothing for a skeleton to stand in for - the old page stays usable until
     * the new one is ready. A bar at the top says "working" without hiding what
     * somebody is still reading, and without blocking the whole interface
     * because one report is slow.
     *
     * ⚠ The 250 ms delay is load-bearing: below it the response usually wins,
     * and a bar that flashes on every click makes a fast system look nervous.
     */
    progress: {
        color: 'var(--primary)',
        delay: 250,
        showSpinner: false,
    },
});

// This will set light / dark mode on load...
initializeTheme();
// Audio is locked until the first click or key press; listen from the very
// first page, the login page included, so the sign-in popup can sound.
installAudioUnlock();
