import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { useEffect, useState } from 'react';

/*
 * ⭐ Joy, 25 Oct 2026 (Q12 = A, R89): the discount on one line is typed as a
 * PERCENT; the taka is worked out from the line and is what the server keeps.
 * Taka may still be typed, and is then shown as a percent too. The person's cap
 * (null = the Owner, no cap) is shown under the box; the server holds it.
 *
 * Used by the order form, the invoice form and the counter.
 */

/** "12.5" from a taka discount on a line worth `gross`; '' when either is missing. */
export function percentOf(taka: string, gross: number): string {
    const value = parseFloat(taka);

    if (!Number.isFinite(value) || value <= 0 || !(gross > 0)) return '';

    return String(Math.round((value * 10000) / gross) / 100);
}

/** The taka for `percent` of a line worth `gross`, to the paisa. */
export function takaFor(percent: string, gross: number): string {
    const value = parseFloat(percent);

    if (!Number.isFinite(value) || value <= 0 || !(gross > 0)) return '';

    return (Math.round(gross * value) / 100).toFixed(2);
}

export function LineDiscount({
    gross,
    value,
    onChange,
    capPercent,
    error,
    compact = false,
    takaPlaceholder,
}: {
    /** The line before the discount (quantity x price, at list price where there is one). */
    gross: number;
    /** The discount in taka, as the form keeps it ('' = none). */
    value: string;
    onChange: (taka: string) => void;
    /** The person's percent, or null for no cap. */
    capPercent: number | null;
    error?: string;
    /** The counter's narrow rows: no hint line under the boxes. */
    compact?: boolean;
    /** What the Tk box says while empty (the invoice: the order's agreed discount). */
    takaPlaceholder?: string;
}) {
    const { t } = useTranslation();
    // The box typed last keeps what was typed; the other is worked out.
    const [typedPercent, setTypedPercent] = useState<string | null>(null);
    const percentShown = typedPercent ?? percentOf(value, gross);

    // A typed percent follows the line: a new quantity or price works the taka out again.
    useEffect(() => {
        if (typedPercent !== null) onChange(takaFor(typedPercent, gross));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [gross]);

    const percentNow = parseFloat(percentOf(value, gross) || '0');
    const over = capPercent !== null && percentNow > capPercent + 0.00001;

    return (
        <div className="grid gap-1">
            <div className="flex items-center gap-1">
                <Input
                    inputMode="decimal"
                    className={`w-16 text-right tabular-nums ${over ? 'border-destructive' : ''}`}
                    placeholder="%"
                    aria-label={t('salesctl.disc.percent')}
                    value={percentShown}
                    onChange={(e) => {
                        setTypedPercent(e.target.value);
                        onChange(takaFor(e.target.value, gross));
                    }}
                />
                <span className="text-muted-foreground text-xs">%</span>
                <Input
                    inputMode="decimal"
                    className="w-24 text-right tabular-nums"
                    placeholder={takaPlaceholder ?? 'Tk'}
                    aria-label={t('salesctl.disc.taka')}
                    value={value}
                    onChange={(e) => {
                        setTypedPercent(null);
                        onChange(e.target.value);
                    }}
                />
            </div>
            {!compact && (
                <p className={`text-xs ${over ? 'text-destructive' : 'text-muted-foreground'}`}>
                    {over
                        ? t('salesctl.disc.over', { percent: capPercent ?? 0 })
                        : capPercent === null
                          ? t('salesctl.disc.no_cap')
                          : t('salesctl.disc.cap', { percent: capPercent })}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
