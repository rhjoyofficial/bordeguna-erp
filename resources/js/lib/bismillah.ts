/**
 * The Bismillah greeting's bookkeeping (owner request, 11 Oct 2026).
 *
 * The greeting shows (a) on the first ERP page after a sign-in and (b) when the
 * person comes back after an hour with no activity in ANY ERP tab. Everything
 * the tabs must agree on lives in localStorage, so it is shared across tabs and
 * survives a reload:
 *
 * - the last activity, written at most every WRITE_EVERY_MS;
 * - when the greeting last opened (so it never shows twice back to back);
 * - the last sign-in nonce greeted (the server issues one per login).
 *
 * ⚠ localStorage can throw (private windows, blocked storage). Every read and
 * write is wrapped, and a module-level copy keeps the greeting working in a
 * single tab even with storage off.
 */

export const IDLE_MS = 60 * 60 * 1000;
export const AUTO_CLOSE_MS = 6000;
const WRITE_EVERY_MS = 15 * 1000;
const CHANNEL = 'erp-bismillah';

const KEYS = {
    activity: 'erp.bismillah.lastActivity',
    shown: 'erp.bismillah.shownAt',
    login: 'erp.bismillah.loginSeen',
    closed: 'erp.bismillah.closedAt',
} as const;

type Key = keyof typeof KEYS;

const memory: Partial<Record<Key, string>> = {};

function read(key: Key): string | null {
    try {
        const value = window.localStorage.getItem(KEYS[key]);

        if (value !== null) {
            return value;
        }
    } catch {
        // storage blocked: fall through to this tab's own copy
    }

    return memory[key] ?? null;
}

function write(key: Key, value: string): void {
    memory[key] = value;

    try {
        window.localStorage.setItem(KEYS[key], value);
    } catch {
        // storage blocked: this tab's copy still holds it
    }
}

function readTime(key: Key): number {
    const value = Number(read(key));

    return Number.isFinite(value) ? value : 0;
}

let lastWrite = 0;
let lastSeen = 0;

/** The latest activity any tab recorded (0 when nothing is known yet). */
export function lastActivity(): number {
    return Math.max(lastSeen, readTime('activity'));
}

/** Records activity in this tab; the shared write is throttled. */
export function recordActivity(now: number, force = false): void {
    lastSeen = Math.max(lastSeen, now);

    if (force || now - lastWrite >= WRITE_EVERY_MS) {
        lastWrite = now;
        write('activity', String(now));
    }
}

/** True when this tab saw activity a moment ago, so a check can be skipped. */
export function recentlySeen(now: number, withinMs: number): boolean {
    return now - lastSeen < withinMs;
}

export function shownAt(): number {
    return readTime('shown');
}

export function markShown(now: number): void {
    write('shown', String(now));
    recordActivity(now, true);
}

/** A sign-in this browser has not greeted yet. */
export function isNewLogin(nonce: string | null | undefined): nonce is string {
    return typeof nonce === 'string' && nonce !== '' && read('login') !== nonce;
}

export function markLoginGreeted(nonce: string): void {
    write('login', nonce);
}

/**
 * The return-after-an-hour rule: an hour with no activity anywhere, and the
 * greeting has not already opened within that hour. A browser with no record
 * at all (first visit) is not a return.
 */
export function isReturn(now: number): boolean {
    const last = lastActivity();

    return last > 0 && now - last >= IDLE_MS && now - shownAt() >= IDLE_MS;
}

export type GreetingMessage = { type: 'open' | 'close'; at: number };

/**
 * Tells the other tabs the greeting opened or closed, and listens for theirs.
 * BroadcastChannel where the browser has it, the storage event otherwise.
 * Returns the sender and the disconnect.
 */
export function connect(onMessage: (message: GreetingMessage) => void): { send: (message: GreetingMessage) => void; close: () => void } {
    let channel: BroadcastChannel | null = null;

    try {
        channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL);
    } catch {
        channel = null;
    }

    if (channel) {
        const handle = (event: MessageEvent<GreetingMessage>) => onMessage(event.data);
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
            onMessage({ type: 'close', at: Number(event.newValue) });
        }
    };
    window.addEventListener('storage', handle);

    return {
        send: (message) => {
            if (message.type === 'close') {
                write('closed', String(message.at));
            }
        },
        close: () => window.removeEventListener('storage', handle),
    };
}
