import type { ScheduleKind, ScheduleSlot } from '@/types';
import { ClipboardList, Coffee, Lightbulb, Moon, UtensilsCrossed, type LucideIcon } from 'lucide-react';

/**
 * The work schedule alert engine's bookkeeping (14 Oct 2026). Pure functions
 * and a little module state, so the alert host (one per page) and the Today's
 * rhythm page read the clock the same way.
 *
 * ⚠ TIME. Every comparison is in Asia/Dhaka wall time, derived from the
 * SERVER's clock: each page load hands over `server_now`, the gap to this
 * machine's clock is kept as an offset, and the Dhaka date and time are read
 * with Intl in the Asia/Dhaka zone. A laptop set to the wrong zone, or a few
 * minutes fast, still alerts on the office's minute.
 *
 * ⚠ WHEN AN ALERT FIRES. Only for a slot whose start is CROSSED while this
 * tab is alive: within START_WINDOW_MS of its start, on one of its weekdays.
 * On the first check after a page load, every slot that has already started
 * today is marked seen without an alert (a reload at 09:30 must not announce
 * the 09:05 slot). A tab that was asleep and wakes up within the window
 * still catches up.
 *
 * ⚠ ONE TAB ALERTS. The tabs race for a lease in localStorage under
 * `erp.schedule.<date>.<slotId>`: write our id, wait a moment, read it back;
 * the tab whose id is still there shows the alert and tells the others on a
 * BroadcastChannel. Storage can throw (private windows): every access is
 * wrapped, and with storage off each tab alerts for itself.
 */

export const CHECK_EVERY_MS = 15 * 1000;
export const START_WINDOW_MS = 2 * 60 * 1000;
const LEASE_SETTLE_MS = 150;
const CHANNEL = 'erp-schedule';
const KEY_PREFIX = 'erp.schedule.';
const TIMEZONE = 'Asia/Dhaka';

export const BUILT_IN_SOUND = '/sounds/success.mp3';

/** The look of each kind: the card, the icon chip, and the screen-edge glow. */
export const KIND_STYLE: Record<ScheduleKind, { icon: LucideIcon; card: string; chip: string; dot: string; edge: string }> = {
    focus: {
        icon: Lightbulb,
        card: 'bg-lime-200/80 text-lime-950 ring-lime-300/70 dark:bg-lime-900/40 dark:text-lime-50 dark:ring-lime-700/60',
        chip: 'bg-lime-600 text-white dark:bg-lime-500 dark:text-lime-950',
        dot: 'bg-lime-500',
        edge: 'rgba(132, 204, 22, 0.75)',
    },
    break: {
        icon: Coffee,
        card: 'bg-pink-200/80 text-pink-950 ring-pink-300/70 dark:bg-pink-900/40 dark:text-pink-50 dark:ring-pink-700/60',
        chip: 'bg-pink-600 text-white dark:bg-pink-500 dark:text-pink-950',
        dot: 'bg-pink-500',
        edge: 'rgba(236, 72, 153, 0.7)',
    },
    meal: {
        icon: UtensilsCrossed,
        card: 'bg-amber-200/80 text-amber-950 ring-amber-300/70 dark:bg-amber-900/40 dark:text-amber-50 dark:ring-amber-700/60',
        chip: 'bg-amber-600 text-white dark:bg-amber-500 dark:text-amber-950',
        dot: 'bg-amber-500',
        edge: 'rgba(245, 158, 11, 0.75)',
    },
    prayer: {
        icon: Moon,
        card: 'bg-sky-200/80 text-sky-950 ring-sky-300/70 dark:bg-sky-900/40 dark:text-sky-50 dark:ring-sky-700/60',
        chip: 'bg-sky-600 text-white dark:bg-sky-500 dark:text-sky-950',
        dot: 'bg-sky-500',
        edge: 'rgba(14, 165, 233, 0.7)',
    },
    wrap: {
        icon: ClipboardList,
        card: 'bg-emerald-200/80 text-emerald-950 ring-emerald-300/70 dark:bg-emerald-900/40 dark:text-emerald-50 dark:ring-emerald-700/60',
        chip: 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-emerald-950',
        dot: 'bg-emerald-500',
        edge: 'rgba(16, 185, 129, 0.7)',
    },
};

// ── The clock ──────────────────────────────────────────────────────────────

let offset = 0;

/** Server time minus this machine's time, refreshed on every page load. */
export function syncClock(serverNow: string | null | undefined): void {
    const server = serverNow ? Date.parse(serverNow) : NaN;

    if (Number.isFinite(server)) {
        offset = server - Date.now();
    }
}

/** This machine's clock corrected to the server's. */
export function serverNow(): number {
    return Date.now() + offset;
}

export interface DhakaTime {
    /** YYYY-MM-DD */
    date: string;
    /** 0 = Sunday .. 6 = Saturday */
    weekday: number;
    /** Seconds since Dhaka midnight. */
    seconds: number;
}

const WEEKDAYS: Record<string, number> = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

let formatter: Intl.DateTimeFormat | null = null;

/** The Dhaka date, weekday and time of day at an instant (never the browser's own zone). */
export function dhakaTime(at: number = serverNow()): DhakaTime {
    formatter ??= new Intl.DateTimeFormat('en-US', {
        timeZone: TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });

    const parts: Record<string, string> = {};

    for (const part of formatter.formatToParts(new Date(at))) {
        parts[part.type] = part.value;
    }

    const hour = Number(parts.hour) % 24;

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        weekday: WEEKDAYS[parts.weekday] ?? 0,
        seconds: hour * 3600 + Number(parts.minute) * 60 + Number(parts.second),
    };
}

/** 'HH:MM' to seconds since midnight. */
export function secondsOf(hm: string): number {
    const [h, m] = hm.split(':').map(Number);

    return (h || 0) * 3600 + (m || 0) * 60;
}

/** '13:30' to '1:30 PM'; English digits everywhere (31 Aug decision). */
export function formatClock(hm: string): string {
    const [h, m] = hm.split(':').map(Number);
    const hour = (h || 0) % 12 === 0 ? 12 : (h || 0) % 12;

    return `${hour}:${String(m || 0).padStart(2, '0')} ${(h || 0) < 12 ? 'AM' : 'PM'}`;
}

/** Today's slots in start order. */
export function slotsOn(slots: ScheduleSlot[], weekday: number): ScheduleSlot[] {
    return slots.filter((slot) => slot.weekdays.includes(weekday)).sort((a, b) => secondsOf(a.starts_at) - secondsOf(b.starts_at));
}

// ── Seen, claimed, announced ─────────────────────────────────────────────

const seen = new Set<string>();
const memory = new Map<string, string>();
let primed = false;
const tabId = Math.random().toString(36).slice(2) + Date.now().toString(36);

export function slotKey(date: string, slotId: number): string {
    return `${KEY_PREFIX}${date}.${slotId}`;
}

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

/** Yesterday's leases are litter: drop every schedule key that is not today's. */
function sweep(today: string): void {
    try {
        const stale: string[] = [];

        for (let index = 0; index < window.localStorage.length; index++) {
            const key = window.localStorage.key(index);

            if (key?.startsWith(KEY_PREFIX) && !key.startsWith(`${KEY_PREFIX}${today}.`)) {
                stale.push(key);
            }
        }

        stale.forEach((key) => window.localStorage.removeItem(key));
    } catch {
        // storage blocked: nothing to sweep
    }
}

export function markSeen(key: string): void {
    seen.add(key);
}

/**
 * The slots due for an alert right now, in this tab. The first call after a
 * page load only records what has already started and returns nothing.
 */
export function dueSlots(slots: ScheduleSlot[], at: number = serverNow()): { slot: ScheduleSlot; key: string }[] {
    const now = dhakaTime(at);
    const today = slotsOn(slots, now.weekday);

    if (!primed) {
        primed = true;
        sweep(now.date);

        for (const slot of today) {
            if (now.seconds >= secondsOf(slot.starts_at)) {
                seen.add(slotKey(now.date, slot.id));
            }
        }

        return [];
    }

    return today
        .filter((slot) => {
            const since = (now.seconds - secondsOf(slot.starts_at)) * 1000;

            return since >= 0 && since < START_WINDOW_MS;
        })
        .map((slot) => ({ slot, key: slotKey(now.date, slot.id) }))
        .filter(({ key }) => !seen.has(key));
}

/**
 * Race the other tabs for this alert. True when this tab won; either way the
 * key is marked seen here, so it is asked once.
 */
export async function claim(key: string): Promise<boolean> {
    seen.add(key);

    if (read(key) !== null) {
        return false;
    }

    write(key, JSON.stringify({ tab: tabId, at: Date.now() }));
    await new Promise((resolve) => window.setTimeout(resolve, LEASE_SETTLE_MS));

    try {
        const lease = JSON.parse(read(key) ?? '{}') as { tab?: string };

        return lease.tab === tabId;
    } catch {
        return false;
    }
}

export type ScheduleMessage = { type: 'shown'; key: string };

/** The cross-tab line: a tab that showed an alert tells the others it is taken. */
export function connect(onMessage?: (message: ScheduleMessage) => void): { send: (message: ScheduleMessage) => void; close: () => void } {
    if (typeof BroadcastChannel === 'undefined') {
        return { send: () => {}, close: () => {} };
    }

    try {
        const channel = new BroadcastChannel(CHANNEL);
        channel.onmessage = (event: MessageEvent<ScheduleMessage>) => {
            if (event.data?.type === 'shown') {
                seen.add(event.data.key);
            }

            onMessage?.(event.data);
        };

        return {
            send: (message) => {
                try {
                    channel.postMessage(message);
                } catch {
                    // a closed channel: the lease already says it is taken
                }
            },
            close: () => channel.close(),
        };
    } catch {
        return { send: () => {}, close: () => {} };
    }
}
