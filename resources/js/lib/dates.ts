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
