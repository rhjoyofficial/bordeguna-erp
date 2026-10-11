import { configureEcho, echo, echoIsConfigured } from '@laravel/echo-react';
import type { ConnectionStatus } from 'laravel-echo';

/**
 * The one Echo bootstrap (Joy, 11 Oct 2026: WebSockets now, through Laravel
 * Reverb). Configured lazily, the first time a live page asks, and only when
 * the build carries a Reverb key: a build without one simply has no socket and
 * every live page falls back to polling on its own.
 *
 * ⚠ Private channel auth is a POST to /broadcasting/auth. It carries the XSRF
 * token read from the cookie Laravel always sets, the same way the other
 * hand-written requests in this app do.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function realtimeAvailable(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

export function ensureEcho(): boolean {
    if (!realtimeAvailable()) {
        return false;
    }

    if (echoIsConfigured()) {
        return true;
    }

    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY as string,
        wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' } },
    });

    return true;
}

interface StatusSource {
    connectionStatus(): ConnectionStatus;
    onConnectionChange(callback: (status: ConnectionStatus) => void): () => void;
}

/** Follow the socket's state; returns the unsubscribe. */
export function watchConnection(callback: (status: ConnectionStatus) => void): () => void {
    if (!ensureEcho()) {
        callback('disconnected');

        return () => {};
    }

    const connector = echo().connector as unknown as StatusSource;
    callback(connector.connectionStatus());

    return connector.onConnectionChange(callback);
}

export { echo };
