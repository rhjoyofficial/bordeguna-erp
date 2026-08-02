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

router.on('before', (event) => {
    if (pageBusinessId !== null) {
        event.detail.visit.headers['X-Business-Id'] = String(pageBusinessId);
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
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
