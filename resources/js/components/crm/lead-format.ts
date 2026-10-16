/**
 * Small formatting helpers the CRM screens share (round 3, 16 Oct 2026).
 *
 * ⚠ Dates arrive as server-built strings in the app's own Asia/Dhaka clock
 * ("2026-10-16 10:30:00"). They are read as LOCAL wall-clock parts, never
 * through `new Date(iso)`, which would shift them by the browser's offset (the
 * 13 Sep bug that printed dates a day early).
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function parts(at: string): { y: number; m: number; d: number; time: string } {
    const [date, time = ''] = at.split(' ');
    const [y, m, d] = date.split('-').map(Number);

    return { y, m, d, time: time.slice(0, 5) };
}

/** "16 Oct". */
export function dayMonth(at: string | null | undefined): string {
    if (!at) return '';
    const { m, d } = parts(at);

    return `${d} ${MONTHS[m - 1] ?? ''}`;
}

/** "10:30", or '' for a date with no time. */
export function clockTime(at: string | null | undefined): string {
    return at ? parts(at).time : '';
}

/** "Today 10:30", "Overdue, 14 Oct" or "18 Oct", against `today` (YYYY-MM-DD). */
export function whenLabel(at: string, today: string, overdue: boolean): string {
    if (at.slice(0, 10) === today) {
        return overdue ? `Overdue, today ${clockTime(at)}` : `Today ${clockTime(at)}`;
    }

    return overdue ? `Overdue, ${dayMonth(at)}` : dayMonth(at);
}

/** "Friday, 16 October 2026" from a YYYY-MM-DD date. */
export function longDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** English digits with thousands separators. */
export function number(value: number): string {
    return value.toLocaleString('en-US');
}

export function plural(count: number, word: string, words = word + 's'): string {
    return `${number(count)} ${count === 1 ? word : words}`;
}

/**
 * A wa.me link for a Bangladeshi number as officers type it ("01711-482930",
 * "+880 1711 482930"), or null when there is nothing to dial.
 */
export function whatsappUrl(phone: string | null | undefined): string | null {
    const digits = (phone ?? '').replace(/\D/g, '');

    if (digits.length < 10) return null;

    const international = digits.startsWith('880') ? digits : digits.startsWith('0') ? '88' + digits : '880' + digits;

    return `https://wa.me/${international}`;
}
