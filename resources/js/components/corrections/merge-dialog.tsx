import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { xsrfToken } from '@/lib/popup-engine';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

const MIN_REASON = 5;

interface DryRun {
    moves?: Record<string, number>;
    balances: Record<string, string>;
    token: string | null;
    kept_url?: string;
    journal?: string | null;
    on?: string;
}

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN section 7 "Merges, Owner only, dry run
 * first"): pick the record to keep, say why, run the dry run, read what would
 * move and the balances before and after, and only then Merge. Changing the
 * pick or the date clears the dry run, so the Owner always confirms exactly
 * what was shown. `withDate` puts the day first (a head's balance moves on a
 * day; a party merge has none).
 */
export function MergeDialog({
    trigger,
    title,
    help,
    keepLabel,
    candidates,
    url,
    reasons,
    confirmLabel,
    withDate = false,
}: {
    trigger: ReactNode;
    title: string;
    help: string;
    keepLabel: string;
    candidates: { id: number; label: string }[];
    url: string;
    reasons: ReasonOption[];
    confirmLabel: string;
    withDate?: boolean;
}) {
    const { t } = useTranslation();
    const { current_business_id } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [into, setInto] = useState('');
    const [on, setOn] = useState(() => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dhaka' }));
    const [reasonId, setReasonId] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [result, setResult] = useState<DryRun | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const ready = into !== '' && reasonText.trim().length >= MIN_REASON;

    const reset = () => {
        setInto('');
        setReasonId('');
        setReasonText('');
        setResult(null);
        setError(null);
        setBusy(false);
    };

    const send = async (dryRun: boolean) => {
        setBusy(true);
        setError(null);

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                body: JSON.stringify({
                    into_id: Number(into),
                    reason_id: reasonId === '' ? null : Number(reasonId),
                    reason_text: reasonText.trim(),
                    dry_run: dryRun,
                    token: dryRun ? null : result?.token,
                    ...(withDate ? { on } : {}),
                }),
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                const errors = (body.errors ?? {}) as Record<string, string[]>;
                setError(Object.values(errors)[0]?.[0] ?? body.message ?? t('corr.fu.merge_failed'));

                if (!dryRun) {
                    setResult(null);
                }

                return;
            }

            if (dryRun) {
                setResult(body as DryRun);

                return;
            }

            setOpen(false);
            reset();
            router.visit((body as DryRun).kept_url ?? window.location.href);
        } catch {
            setError(t('corr.fu.merge_failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    reset();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{help}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    {withDate && (
                        <div className="grid justify-items-start gap-2">
                            <Label htmlFor="merge-on" required>
                                {t('corr.fu.move_on')}
                            </Label>
                            <Input
                                id="merge-on"
                                type="date"
                                className="w-auto"
                                value={on}
                                onChange={(e) => {
                                    setOn(e.target.value);
                                    setResult(null);
                                }}
                            />
                        </div>
                    )}

                    <div className="grid justify-items-start gap-2">
                        <Label htmlFor="merge-into" required>
                            {keepLabel}
                        </Label>
                        <SearchSelect
                            fit
                            id="merge-into"
                            value={into}
                            onChange={(e) => {
                                setInto(e.target.value);
                                setResult(null);
                            }}
                        >
                            <option value="">{t('corr.fu.choose')}</option>
                            {candidates.map((candidate) => (
                                <option key={candidate.id} value={candidate.id}>
                                    {candidate.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="merge-reason-id">{t('corr.reason')}</Label>
                        <select
                            id="merge-reason-id"
                            value={reasonId}
                            onChange={(e) => setReasonId(e.target.value)}
                            className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                        >
                            <option value="">{t('corr.reason_pick')}</option>
                            {reasons.map((reason) => (
                                <option key={reason.id} value={reason.id}>
                                    {reason.name}
                                </option>
                            ))}
                        </select>
                        <Label htmlFor="merge-reason-text" required>
                            {t('corr.reason_words')}
                        </Label>
                        <textarea
                            id="merge-reason-text"
                            aria-required
                            value={reasonText}
                            onChange={(e) => {
                                setReasonText(e.target.value);
                                setResult(null);
                            }}
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-20 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </div>

                    {result && (
                        <div className="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/40">
                            <p className="font-medium">{t('corr.fu.dry_run_result')}</p>
                            {result.on && <p>{t('corr.fu.moves_on', { date: showDate(result.on) })}</p>}
                            {result.moves && (
                                <ul className="list-disc pl-5">
                                    {Object.keys(result.moves).length === 0 && <li>{t('corr.fu.nothing_moves')}</li>}
                                    {Object.entries(result.moves).map(([where, rows]) => (
                                        <li key={where}>
                                            {t('corr.fu.rows_move', { rows: String(rows), table: where.split('.')[0].replaceAll('_', ' ') })}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <ul className="pl-0">
                                {Object.entries(result.balances).map(([key, amount]) => (
                                    <li key={key} className="flex justify-between gap-4 tabular-nums">
                                        <span>{t(`corr.fu.balance_${key}` as Parameters<typeof t>[0])}</span>
                                        <span>{amount}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {error && <p className="text-destructive">{error}</p>}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.go_back')}
                    </Button>
                    <Button type="button" variant="outline" disabled={!ready || busy} onClick={() => void send(true)}>
                        {t('corr.fu.dry_run')}
                    </Button>
                    <Button type="button" variant="destructive" disabled={!ready || busy || !result?.token} onClick={() => void send(false)}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
