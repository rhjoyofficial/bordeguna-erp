/**
 * Today's date in Dhaka, as the Y-m-d a date input takes.
 *
 * ⚠⚠ Never `new Date().toISOString().slice(0, 10)`. That is the date in UTC,
 * which from midnight to 6 am in Dhaka is still YESTERDAY, so a price, a bill or
 * a refund entered first thing in the morning defaulted to the day before
 * (Sales UX audit #12, 21 Sep 2026). The business keeps Dhaka's calendar
 * (config/app.php), whatever clock the browser happens to be set to.
 */
export function dhakaToday(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' });
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * A date column (Y-m-d, or Y-m-d H:i:s) as "30 Sep 2027": day, short month,
 * and ALWAYS the year (practice run F18, 17 Oct 2026: "expires 30 Sept" left the
 * year to guess). Built by hand, not with toLocaleDateString, so September is
 * "Sep" in every browser and the day is never shifted by the browser's time zone.
 * ⚠ For DATE columns: a UTC timestamp would show its UTC day. Anything that
 * is not a date comes back as it was; empty is "-".
 */
export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '-';
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) return iso;
    const month = MONTHS[Number(match[2]) - 1];
    if (!month) return iso;

    return `${Number(match[3])} ${month} ${match[1]}`;
}

/**
 * A wall-clock time the server already wrote in Dhaka time (Y-m-d H:i:s) as
 * "17 Oct 2026, 09:12" (re-check F40, 17 Oct 2026). Only for strings the
 * server built with toDateTimeString(), never a UTC ISO timestamp. A bare date
 * comes back as formatDate gives it; anything else as it was; empty is "-".
 */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '-';
    const time = /^\d{4}-\d{2}-\d{2} (\d{2}):(\d{2})/.exec(value);

    return time ? `${formatDate(value)}, ${time[1]}:${time[2]}` : formatDate(value);
}

/*
 * ⚠⚠ DD/MM/YYYY EVERYWHERE, DHAKA TIME EVERYWHERE (Joy, 18 Oct 2026: "in our
 * country we always write Day Month Year", "time Dhaka Asia +6").
 *
 * Everything below is built by hand or with an explicit timeZone, never from
 * the browser's own zone or locale, so a PC set to New York or en-US still
 * shows 17/09/2026 and Dhaka's clock. A bare Y-m-d is a CALENDAR DATE and is
 * never sent through `new Date()` (that would read it as UTC midnight and can
 * shift it a day).
 */
export const DHAKA_TZ = 'Asia/Dhaka';

const pad = (n: number) => String(n).padStart(2, '0');

/** Days in a month (month 1-12). */
export function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** A Y-m-d (or a longer string starting with one) as "17/09/2026"; anything else as it was, empty as ''. */
export function dmy(iso: string | null | undefined): string {
    if (!iso) return '';
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    return match ? `${match[3]}/${match[2]}/${match[1]}` : iso;
}

/** A Y-m as "09/2026". */
export function my(ym: string | null | undefined): string {
    if (!ym) return '';
    const match = /^(\d{4})-(\d{2})$/.exec(ym);

    return match ? `${match[2]}/${match[1]}` : ym;
}

let dhakaParts: Intl.DateTimeFormat | null = null;

/** The Dhaka calendar date and wall clock at an instant. */
export function dhakaClock(at: Date | number = Date.now()): { date: string; time: string; hour: number; minute: number } {
    dhakaParts ??= new Intl.DateTimeFormat('en-GB', {
        timeZone: DHAKA_TZ,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const parts: Record<string, string> = {};
    for (const part of dhakaParts.formatToParts(typeof at === 'number' ? new Date(at) : at)) parts[part.type] = part.value;
    const hour = Number(parts.hour) % 24;

    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${pad(hour)}:${parts.minute}`, hour, minute: Number(parts.minute) };
}

/** "Now" in Dhaka as a datetime-local value: 2026-10-18T09:05. */
export function dhakaNowLocal(): string {
    const now = dhakaClock();

    return `${now.date}T${now.time}`;
}

/** A Y-m-d moved by whole days / months, as calendar arithmetic (no time zone involved). */
export function addDays(iso: string, days: number): string {
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/**
 * Any date-ish value the server or the page holds, for reading: "17/09/2026" or
 * "17/09/2026 14:05".
 *  - "2026-09-17"                     -> 17/09/2026 (a calendar date, never shifted)
 *  - "2026-09-17 14:05[:00]" / "...T14:05" (no zone) -> Dhaka wall clock as written
 *  - "2026-09-17T08:05:00Z" / "+00:00" (an instant)  -> converted to Dhaka
 * Anything else (already words, a dash) comes back as it was; empty is ''.
 */
export function showDate(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    if (typeof value !== 'string') return String(value);

    const instant = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/.exec(value);
    if (instant) {
        const at = new Date(value.replace(' ', 'T'));
        if (!Number.isNaN(at.getTime())) {
            const clock = dhakaClock(at);

            return `${dmy(clock.date)} ${clock.time}`;
        }
    }

    const wall = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/.exec(value);
    if (wall) return `${dmy(wall[1])} ${wall[2]}:${wall[3]}`;

    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return dmy(value);

    return value;
}

/** An instant (epoch ms or a Date) as Dhaka "17/09/2026 14:05". */
export function showInstant(at: number | Date | null | undefined): string {
    if (at === null || at === undefined) return '';
    const date = typeof at === 'number' ? new Date(at) : at;
    if (Number.isNaN(date.getTime())) return '';
    const clock = dhakaClock(date);

    return `${dmy(clock.date)} ${clock.time}`;
}

/** Only the time of day in Dhaka (24 h, "14:05") of an ISO instant or a server wall-clock string. */
export function showTime(value: string | null | undefined): string {
    const shown = showDate(value);
    const match = / (\d{2}:\d{2})$/.exec(shown);

    return match ? match[1] : '';
}

/**
 * What a person typed into a date box, as Y-m-d, or null when it is not a real
 * date. Reads DAY first: 5/9/2026, 05-09-2026, 05.09.2026 and 05092026 are all
 * 5 September 2026. A pasted ISO 2026-09-05 is read as ISO. A two-digit year is
 * 20xx. Bangla digits are accepted.
 */
export function parseDmy(text: string | null | undefined): string | null {
    if (!text) return null;
    const latin = Array.from(text.trim(), (ch) => {
        const code = ch.charCodeAt(0);

        return code >= 0x09e6 && code <= 0x09ef ? String(code - 0x09e6) : ch;
    }).join('');

    let y: number, m: number, d: number;
    let match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(latin);
    if (match) {
        [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else if ((match = /^(\d{1,2})[/.\-\s](\d{1,2})[/.\-\s](\d{4}|\d{2})$/.exec(latin))) {
        [d, m, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
        if (match[3].length === 2) y += 2000;
    } else if ((match = /^(\d{2})(\d{2})(\d{4})$/.exec(latin))) {
        [d, m, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
    } else {
        return null;
    }
    if (y < 1900 || y > 2200 || m < 1 || m > 12 || d < 1 || d > daysInMonth(y, m)) return null;

    return `${y}-${pad(m)}-${pad(d)}`;
}

/** A typed month "09/2026", "9/2026", "2026-09" as Y-m, or null. */
export function parseMy(text: string | null | undefined): string | null {
    if (!text) return null;
    const value = text.trim();
    let match = /^(\d{4})-(\d{1,2})$/.exec(value);
    let y: number, m: number;
    if (match) {
        [y, m] = [Number(match[1]), Number(match[2])];
    } else if ((match = /^(\d{1,2})[/.\-\s](\d{4})$/.exec(value))) {
        [m, y] = [Number(match[1]), Number(match[2])];
    } else {
        return null;
    }
    if (y < 1900 || y > 2200 || m < 1 || m > 12) return null;

    return `${y}-${pad(m)}`;
}

/** Bangla digits as Latin ones; everything else unchanged. */
export function banglaToLatin(text: string): string {
    return Array.from(text, (ch) => {
        const code = ch.charCodeAt(0);

        return code >= 0x09e6 && code <= 0x09ef ? String(code - 0x09e6) : ch;
    }).join('');
}

/** Typed text as the box shows it: slashes added, day and month never longer than two digits. */
export function maskDate(raw: string, previous: string): string {
    const text = banglaToLatin(raw).trim();
    if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(text)) {
        const iso = parseDmy(text);

        return iso ? dmy(iso) : text;
    }
    const cleaned = text
        .replace(/[.\-\s]/g, '/')
        .replace(/[^\d/]/g, '')
        .replace(/\/{2,}/g, '/');
    const parts = cleaned.split('/');
    for (let i = 0; i < 2 && i < parts.length; i++) {
        if (parts[i].length > 2) {
            const rest = parts[i].slice(2);
            parts[i] = parts[i].slice(0, 2);
            if (i + 1 < parts.length) parts[i + 1] = rest + parts[i + 1];
            else parts.push(rest);
        }
    }
    if (parts.length > 3) parts.length = 3;
    if (parts[2] !== undefined) parts[2] = parts[2].slice(0, 4);
    let masked = parts.join('/');
    if (masked.length > previous.length && parts.length < 3 && parts[parts.length - 1].length === 2) masked += '/';

    return masked;
}

/** A finished DD/MM/YYYY as Y-m-d (four-digit year only while typing). */
export function completeDate(text: string, allowShortYear = false): string | null {
    const ok = allowShortYear ? /^\d{1,2}\/\d{1,2}\/(\d{4}|\d{2})$/ : /^\d{1,2}\/\d{1,2}\/\d{4}$/;

    return ok.test(text) ? parseDmy(text) : null;
}

/** Typed text in a month box as it shows: "09/2026". */
export function maskMonth(raw: string, previous: string): string {
    const text = banglaToLatin(raw).trim();
    if (/^\d{4}-\d{1,2}$/.test(text)) {
        const ym = parseMy(text);

        return ym ? my(ym) : text;
    }
    const cleaned = text
        .replace(/[.\-\s]/g, '/')
        .replace(/[^\d/]/g, '')
        .replace(/\/{2,}/g, '/');
    const parts = cleaned.split('/');
    if (parts[0].length > 2) {
        parts.splice(1, 0, parts[0].slice(2));
        parts[0] = parts[0].slice(0, 2);
    }
    if (parts.length > 2) parts.length = 2;
    if (parts[1] !== undefined) parts[1] = parts[1].slice(0, 4);
    let masked = parts.join('/');
    if (masked.length > previous.length && parts.length === 1 && parts[0].length === 2) masked += '/';

    return masked;
}

/**
 * A sentence the server wrote ("add a punch at 08:10 on 2026-09-23") with every
 * bare Y-m-d in it as DD/MM/YYYY. Only for text that is read, never for a value
 * sent back. A date glued to a word or a dash (INV-2026-09-17) is left alone.
 */
export function datesInText(text: string | null | undefined): string {
    if (!text) return '';

    return text.replace(/(?<![\w/-])(\d{4})-(\d{2})-(\d{2})(?![\w-])/g, '$3/$2/$1');
}
