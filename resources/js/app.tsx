import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

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
const inFlightMutations = new Set<string>();

const mutationKey = (visit: { method: string; url: URL | string }): string | null => {
    const method = String(visit.method).toLowerCase();
    if (method === 'get') {
        return null;
    }

    return `${method} ${visit.url.toString()}`;
};

router.on('before', (event) => {
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
    }
});

router.on('success', (event) => {
    pageBusinessId = (event.detail.page.props.current_business_id as number | null) ?? null;
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        // The first page is rendered without a visit, so `success` never fires
        // for it. Without this the very first action in a fresh tab would be
        // unstamped, and §13.6 fails those closed.
        pageBusinessId = (props.initialPage.props.current_business_id as number | null) ?? null;

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
