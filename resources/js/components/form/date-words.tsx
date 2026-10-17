import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

/**
 * The date a native date box holds, written out: "= 30 Sep 2027".
 *
 * ⚠ 17 Oct 2026 (practice run F14): the browser draws a date box in the
 * computer's own order, mm/dd/yyyy on an American-set Windows, and a page
 * cannot change that. Replacing every date box with a hand-made picker was the
 * riskier fix (keyboard entry, mobile pickers, min/max, 60+ screens), so the
 * box stays native and this line under or beside it says, in words, which day
 * was chosen: 09/10/2027 can no longer be read as 9 Oct when it is 10 Sep.
 */
export function DateWords({ value, className }: { value: string | null | undefined; className?: string }) {
    if (!value) return null;

    return <span className={cn('text-muted-foreground text-xs whitespace-nowrap tabular-nums', className)}>= {formatDate(value)}</span>;
}
