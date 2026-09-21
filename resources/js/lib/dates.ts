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
