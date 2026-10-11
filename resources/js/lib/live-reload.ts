import { router } from '@inertiajs/react';

/**
 * Realtime everywhere (Joy, 11 Oct 2026: "Everything shows real time, no
 * reload, good decision for everywhere").
 *
 * The server broadcasts `record.changed` (kind + id, never a field value) on
 * business.{id} and user.{id}. Each page declares the kinds it shows (the
 * shared `live` prop, from App\Support\Realtime\LivePages). When one of those
 * arrives this module re-reads the page with ONE Inertia reload: Inertia keeps
 * scroll and component state, so a half-typed field, an open tab or a chosen
 * filter survive it.
 *
 * ⚠ Module state, not React state: the layout remounts on every navigation and
 * the queue, the in-flight writes and the socket state must outlive it.
 *
 * ⚠⚠ A RELOAD IS DEFERRED, NEVER DROPPED, while the person is in the middle of
 * something: a write in flight, focus in a field, a dialog or menu open, a form
 * typed into since the page loaded, the tab hidden. It retries every second and
 * the moment the tab is shown again, and however many events piled up it is
 * still one reload.
 */

export interface RecordChangedEvent {
    kind: string;
    id: number | string;
    action: 'created' | 'updated' | 'deleted';
    business_id: number | null;
    actor_id: number | null;
    at: string;
}

export interface LiveContext {
    userId: number | null;
    canReviewApprovals: boolean;
}

/** Bursts (a 30-line sale, a bulk approval) collapse into one reload. */
const DEBOUNCE_MS = 500;
const RETRY_MS = 1000;
/** My own write already redirected and refreshed this tab. */
const OWN_WRITE_WINDOW_MS = 4000;

/** The header bell's own props: refreshed on every page, without touching the page. */
const BELL_PROPS = ['unreadNotificationsCount', 'recentNotifications'];
const APPROVALS_PROPS = ['pendingApprovalsCount'];

// ─── State shared with app.tsx's visit hooks ─────────────────────────────

/** Mutating visits in flight, keyed "method url" (the double-submit guard). */
export const inFlightMutations = new Set<string>();

let lastWriteFinishedAt = 0;
let pageIsReloadable = true;
let pageOptsOut = false;
let pageLive: string[] = [];
let socketConnected = false;
let everConnected = false;
let formDirty = false;
let liveVisiting = false;

let pendingFull = false;
const pendingOnly = new Set<string>();
let timer: number | null = null;

const refreshedListeners = new Set<() => void>();

export function markWriteFinished(): void {
    lastWriteFinishedAt = Date.now();
}

/** Called on every successful visit (app.tsx). */
export function setPageState(state: { reloadable?: boolean; optsOut: boolean; live: string[] | undefined }): void {
    if (state.reloadable !== undefined) {
        pageIsReloadable = state.reloadable;
    }

    pageOptsOut = state.optsOut;
    pageLive = Array.isArray(state.live) ? state.live : [];

    // A new page, or the page after my own submit, starts clean. A live
    // refresh of the same page does not: the person is still where they were.
    if (!liveVisiting) {
        formDirty = false;
    }
}

export function setSocketConnected(connected: boolean): void {
    const reconnected = connected && !socketConnected && everConnected;
    socketConnected = connected;

    if (connected) {
        everConnected = true;
    }

    // Events sent while we were away are lost: read the page once on the way back.
    if (reconnected && pageLive.length > 0 && !pageOptsOut) {
        pendingFull = true;
        schedule(DEBOUNCE_MS);
    }
}

/**
 * Whether the socket refreshes this page, so the 30 s poll can stand down. A
 * page that declared nothing keeps the poll: a screen nobody wired never goes
 * stale.
 */
export function socketCoversPage(): boolean {
    return socketConnected && pageLive.length > 0;
}

export function onLiveRefreshed(listener: () => void): () => void {
    refreshedListeners.add(listener);

    return () => refreshedListeners.delete(listener);
}

// ─── Receiving ───────────────────────────────────────────────────────────

export function receive(event: RecordChangedEvent, context: LiveContext): void {
    const mine = event.actor_id !== null && event.actor_id === context.userId;

    if (mine && Date.now() - lastWriteFinishedAt < OWN_WRITE_WINDOW_MS) {
        return;
    }

    if (event.kind === 'notification') {
        BELL_PROPS.forEach((key) => pendingOnly.add(key));
    }

    if (event.kind === 'approval_request' && context.canReviewApprovals) {
        APPROVALS_PROPS.forEach((key) => pendingOnly.add(key));
    }

    if (!pageOptsOut && pageIsReloadable && (pageLive.includes(event.kind) || pageLive.includes('*'))) {
        pendingFull = true;
    }

    if (pendingFull || pendingOnly.size > 0) {
        schedule(DEBOUNCE_MS);
    }
}

function schedule(ms: number): void {
    if (timer !== null) {
        window.clearTimeout(timer);
    }

    timer = window.setTimeout(flush, ms);
}

/** Focus in a field the person is typing in. */
function editing(): boolean {
    const el = document.activeElement as HTMLElement | null;

    if (!el) {
        return false;
    }

    if (el.isContentEditable || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
        return true;
    }

    if (el.tagName === 'INPUT') {
        const type = (el as HTMLInputElement).type;

        return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color'].includes(type);
    }

    return false;
}

/** A dialog, sheet, menu, select or popover is open. */
function overlayOpen(): boolean {
    return (
        document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"], [data-radix-popper-content-wrapper]') !== null
    );
}

function personIsBusy(): boolean {
    return formDirty || editing() || overlayOpen();
}

function flush(): void {
    timer = null;

    if (inFlightMutations.size > 0 || document.visibilityState !== 'visible') {
        schedule(RETRY_MS);

        return;
    }

    if (pendingFull && (!pageIsReloadable || pageOptsOut)) {
        pendingFull = false;
    }

    if (pendingFull && !personIsBusy()) {
        pendingFull = false;
        pendingOnly.clear();
        visit(undefined);

        return;
    }

    // The bell never touches the page, so it may refresh while the person types.
    if (pendingOnly.size > 0) {
        const only = [...pendingOnly];
        pendingOnly.clear();
        visit(only);
    }

    if (pendingFull) {
        schedule(RETRY_MS);
    }
}

function visit(only: string[] | undefined): void {
    liveVisiting = true;

    // reload() always keeps scroll and component state (Inertia v2).
    router.reload({
        ...(only ? { only } : {}),
        showProgress: false,
        onSuccess: () => {
            if (!only) {
                refreshedListeners.forEach((listener) => listener());
            }
        },
        onFinish: () => {
            liveVisiting = false;
        },
    });
}

// ─── Watching the person ─────────────────────────────────────────────────

if (typeof document !== 'undefined') {
    // Typing inside a form or a dialog makes the page "dirty" until the next
    // visit (a submit, a navigation). A table's search box is not a form.
    document.addEventListener(
        'input',
        (event) => {
            const target = event.target as HTMLElement | null;

            if (target?.closest('form, [role="dialog"], [role="alertdialog"]')) {
                formDirty = true;
            }
        },
        true,
    );

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && (pendingFull || pendingOnly.size > 0)) {
            schedule(0);
        }
    });
}
