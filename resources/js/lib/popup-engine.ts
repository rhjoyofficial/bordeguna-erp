import type { PopupItem } from '@/types';

/**
 * The popup engine's bookkeeping (14 Oct 2026), grown out of the Bismillah
 * greeting's (11 Oct). A popup shows (a) on the first ERP page after a
 * sign-in and (b) when the person comes back after its idle time with no
 * activity in ANY ERP tab. Everything the tabs must agree on lives in
 * localStorage, so it is shared across tabs and survives a reload:
 *
 * - the last activity, written at most every WRITE_EVERY_MS;
 * - when each popup last opened (so it never shows twice back to back);
 * - the last sign-in nonce greeted (the server issues one per login).
 *
 * ⚠ The Bismillah popup keeps the 11 Oct storage keys, so nobody gets an
 * extra greeting on deploy day.
 *
 * ⚠ localStorage can throw (private windows, blocked storage). Every read and
 * write is wrapped, and a module-level copy keeps the engine working in a
 * single tab even with storage off.
 */

export const AUTO_CLOSE_MS = 6000;
const WRITE_EVERY_MS = 15 * 1000;
const CHANNEL = 'erp-bismillah';

const KEYS = {
    activity: 'erp.bismillah.lastActivity',
    login: 'erp.bismillah.loginSeen',
    closed: 'erp.bismillah.closedAt',
} as const;

const memory = new Map<string, string>();

function read(key: string): string | null {
    try {
        const value = window.localStorage.getItem(key);

        if (value !== null) {
            return value;
        }
    } catch {
        // storage blocked: fall through to this tab's own copy
    }

    return memory.get(key) ?? null;
}

function write(key: string, value: string): void {
    memory.set(key, value);

    try {
        window.localStorage.setItem(key, value);
    } catch {
        // storage blocked: this tab's copy still holds it
    }
}

function readTime(key: string): number {
    const value = Number(read(key));

    return Number.isFinite(value) ? value : 0;
}

function shownKey(item: Pick<PopupItem, 'id' | 'key'>): string {
    return item.key === 'bismillah' ? 'erp.bismillah.shownAt' : `erp.popup.${item.id}.shownAt`;
}

let lastWrite = 0;
let lastSeen = 0;

/** The latest activity any tab recorded (0 when nothing is known yet). */
export function lastActivity(): number {
    return Math.max(lastSeen, readTime(KEYS.activity));
}

/** Records activity in this tab; the shared write is throttled. */
export function recordActivity(now: number, force = false): void {
    lastSeen = Math.max(lastSeen, now);

    if (force || now - lastWrite >= WRITE_EVERY_MS) {
        lastWrite = now;
        write(KEYS.activity, String(now));
    }
}

/** True when this tab saw activity a moment ago, so a check can be skipped. */
export function recentlySeen(now: number, withinMs: number): boolean {
    return now - lastSeen < withinMs;
}

export function shownAt(item: Pick<PopupItem, 'id' | 'key'>): number {
    return readTime(shownKey(item));
}

export function markShown(item: Pick<PopupItem, 'id' | 'key'>, now: number): void {
    write(shownKey(item), String(now));
    recordActivity(now, true);
}

/** A sign-in this browser has not greeted yet. */
export function isNewLogin(nonce: string | null | undefined): nonce is string {
    return typeof nonce === 'string' && nonce !== '' && read(KEYS.login) !== nonce;
}

export function markLoginGreeted(nonce: string): void {
    write(KEYS.login, nonce);
}

/**
 * The return-after-idle rule for one popup: its idle time with no activity
 * anywhere, and it has not already opened within that time. A browser with
 * no record at all (first visit) is not a return.
 */
export function isIdleDue(item: PopupItem, now: number): boolean {
    if (item.idle_minutes === null || item.idle_minutes <= 0) {
        return false;
    }

    const idleMs = item.idle_minutes * 60 * 1000;
    const last = lastActivity();

    return last > 0 && now - last >= idleMs && now - shownAt(item) >= idleMs;
}

/** Highest priority first, then the oldest row: a stable order every tab agrees on. */
export function byPriority(a: PopupItem, b: PopupItem): number {
    return b.priority - a.priority || a.id - b.id;
}

/**
 * The quiet-hours rule the dashboards use, applied to popup sounds too.
 * `from`/`to` are "HH:MM"; a window may cross midnight.
 */
export function inQuietHours(quiet: { from: string; to: string } | null, now = new Date()): boolean {
    if (!quiet) {
        return false;
    }

    const minutes = now.getHours() * 60 + now.getMinutes();
    const parse = (value: string) => {
        const [h, m] = value.split(':').map(Number);

        return (h || 0) * 60 + (m || 0);
    };
    const from = parse(quiet.from);
    const to = parse(quiet.to);

    return from <= to ? minutes >= from && minutes < to : minutes >= from || minutes < to;
}

/*
 * The gate: a screen in the middle of something (the counter with a sale on
 * the till) holds popups back; they show once it lets go. A held popup is
 * not consumed: its once-rule claim happens only when it actually opens.
 */
const holds = new Set<string>();
const gateListeners = new Set<() => void>();

export function holdPopups(reason: string): void {
    holds.add(reason);
}

export function releasePopups(reason: string): void {
    if (holds.delete(reason)) {
        gateListeners.forEach((listener) => listener());
    }
}

export function popupsHeld(): boolean {
    return holds.size > 0;
}

export function onPopupsReleased(listener: () => void): () => void {
    gateListeners.add(listener);

    return () => gateListeners.delete(listener);
}

export type PopupMessage = { type: 'open' | 'close'; id: number; at: number };

/**
 * Tells the other tabs a popup opened or closed, and listens for theirs.
 * BroadcastChannel where the browser has it, the storage event otherwise.
 * Returns the sender and the disconnect.
 */
export function connect(onMessage: (message: PopupMessage) => void): { send: (message: PopupMessage) => void; close: () => void } {
    let channel: BroadcastChannel | null = null;

    try {
        channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL);
    } catch {
        channel = null;
    }

    if (channel) {
        const handle = (event: MessageEvent<PopupMessage>) => onMessage(event.data);
        channel.addEventListener('message', handle);

        return {
            send: (message) => channel?.postMessage(message),
            close: () => {
                channel?.removeEventListener('message', handle);
                channel?.close();
            },
        };
    }

    const handle = (event: StorageEvent) => {
        if (event.key === KEYS.closed && event.newValue) {
            const [id, at] = event.newValue.split(':').map(Number);
            onMessage({ type: 'close', id: id || 0, at: at || 0 });
        }
    };
    window.addEventListener('storage', handle);

    return {
        send: (message) => {
            if (message.type === 'close') {
                write(KEYS.closed, `${message.id}:${message.at}`);
            }
        },
        close: () => window.removeEventListener('storage', handle),
    };
}

/** The XSRF token from the cookie Laravel sets; the layout has no meta tag for it. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
