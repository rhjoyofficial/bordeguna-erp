import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { type FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

/**
 * The one dialog every Reverse and Correct goes through (edit, delete and
 * correct, Phase 0, 17 Oct 2026, PHASE-0-CONTRACT v2 1.6).
 *
 * ⚠ The name ReasonDialog is taken (components/settings/reason-dialog.tsx, the
 * Settings popup that adds a reason to a list); this is ActionDialog.
 *
 * What it does, in order:
 *  1. On open it makes an idempotency key and keeps it until it closes, so a
 *     double click or a retry can never make a second correction.
 *  2. Once the reason has 5 characters it asks the server for a PREVIEW and
 *     shows three facts: the date it lands on, the effect, and what stays.
 *     Blockers grey out the confirm button and say why in a sentence.
 *  3. The confirm button is the verb ("Reverse", "Correct", "Send for
 *     approval", "Ask the Owner"), enabled only with a preview token.
 *  4. Submit is an Inertia post. "Changed after you opened it" reloads the
 *     preview; "Possible duplicate" shows the candidates with "Save anyway".
 *
 * ⚠ The server checks everything again; this dialog decides nothing.
 */

export type ActionVerb = 'reverse' | 'correct';

type Blocker = { code: string; message: string; url: string | null; requestable: boolean };

type Candidate = {
    number: string | null;
    summary: string;
    saved_by: string | null;
    saved_ago: string | null;
    status: string | null;
    url: string | null;
};

type Preview = {
    preview_token: string | null;
    title: string;
    confirm_label: string;
    date: string;
    effects: string[];
    stays: string[];
    blockers: Blocker[];
    approval: 'none' | 'second_person' | 'owner_closed_period' | 'both';
    amount_basis: string;
    duplicate: { candidates: Candidate[]; ack_token: string } | null;
    warnings: string[];
};

type ActionDialogProps = {
    trigger: ReactNode;
    action: ActionVerb;
    recordLabel: string;
    previewUrl: string;
    submitUrl: string;
    reasons: { id: number; name: string }[];
    /** Correct: the edited form. */
    replacement?: Record<string, FormDataConvertible>;
    /** Reverse only, and only when the record's month is open. */
    allowToday?: boolean;
    /** Where to land after it is done (a path on this site). */
    redirectTo?: string;
};

const MIN_REASON = 5;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
}

export function ActionDialog({
    trigger,
    action,
    recordLabel,
    previewUrl,
    submitUrl,
    reasons,
    replacement,
    allowToday = false,
    redirectTo,
}: ActionDialogProps) {
    const { current_business_id, duplicateWarning } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState('');
    const [reasonId, setReasonId] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [dateChoice, setDateChoice] = useState<'original' | 'today'>('original');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [ack, setAck] = useState<string | null>(null);
    const [duplicate, setDuplicate] = useState<Preview['duplicate']>(null);
    const request = useRef(0);

    const verb = action === 'correct' ? 'Correct' : 'Reverse';
    const ready = reasonText.trim().length >= MIN_REASON;

    const body = useCallback(
        () => ({
            reason_id: reasonId === '' ? null : Number(reasonId),
            reason_text: reasonText.trim(),
            date_choice: action === 'reverse' ? dateChoice : 'original',
            idempotency_key: key,
            ...(replacement ? { replacement } : {}),
        }),
        [reasonId, reasonText, dateChoice, key, replacement, action],
    );

    const loadPreview = useCallback(async () => {
        if (!ready || key === '') {
            setPreview(null);

            return;
        }

        const mine = ++request.current;
        setLoading(true);
        setProblem(null);

        try {
            const response = await fetch(previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
                },
                body: JSON.stringify(body()),
            });

            if (mine !== request.current) {
                return;
            }

            if (response.status === 422) {
                const payload = (await response.json()) as { errors?: Record<string, string[]>; message?: string };
                const first = payload.errors ? Object.values(payload.errors)[0]?.[0] : payload.message;
                setPreview(null);
                setProblem(first ?? 'Please check the form.');

                return;
            }

            if (!response.ok) {
                const payload = (await response.json().catch(() => null)) as { message?: string } | null;
                setPreview(null);
                setProblem(payload?.message ?? 'This cannot be done here. Reload the page and try again.');

                return;
            }

            const data = (await response.json()) as Preview;
            setPreview(data);
            setDuplicate(data.duplicate);
        } catch {
            if (mine === request.current) {
                setPreview(null);
                setProblem('The preview could not be loaded. Check the connection and try again.');
            }
        } finally {
            if (mine === request.current) {
                setLoading(false);
            }
        }
    }, [ready, key, previewUrl, current_business_id, body]);

    // A short pause after typing, so the preview follows the reason without a
    // request per key press. The reason and the date are part of what the
    // preview seals, so a change asks again.
    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = window.setTimeout(() => void loadPreview(), 350);

        return () => window.clearTimeout(timer);
    }, [open, loadPreview]);

    const reset = () => {
        setReasonId('');
        setReasonText('');
        setDateChoice('original');
        setPreview(null);
        setProblem(null);
        setAck(null);
        setDuplicate(null);
        setSubmitting(false);
    };

    const submit = (ackToken: string | null = ack) => {
        if (!preview?.preview_token) {
            return;
        }

        setSubmitting(true);
        router.post(
            submitUrl,
            { ...body(), preview_token: preview.preview_token, duplicate_ack: ackToken, ...(redirectTo ? { redirect_to: redirectTo } : {}) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    reset();
                },
                onError: (errors) => {
                    if (errors._duplicate) {
                        setProblem(null);

                        return;
                    }

                    if (errors.stale || errors.preview_token) {
                        setProblem(errors.stale ?? errors.preview_token);
                        void loadPreview();

                        return;
                    }

                    setProblem(errors.correction ?? Object.values(errors)[0] ?? 'This could not be done.');
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    // "Possible duplicate" comes back as a flashed prop after a refused submit.
    useEffect(() => {
        if (open && duplicateWarning) {
            setDuplicate(duplicateWarning);
        }
    }, [open, duplicateWarning]);

    const hardBlocked = (preview?.blockers ?? []).length > 0;
    const canConfirm = ready && !!preview?.preview_token && !hardBlocked && !loading && !submitting;
    // A possible duplicate is answered with "Save anyway" (or Go back), never
    // with the plain confirm button.
    const needsAck = !!duplicate && duplicate.candidates.length > 0;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setKey(newKey());
                } else {
                    reset();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{preview?.title ?? `${verb} ${recordLabel}?`}</DialogTitle>
                    <DialogDescription>
                        The record stays, marked {action === 'correct' ? 'Corrected' : 'Reversed'}, with your reason.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    <div className="grid gap-2">
                        <Label htmlFor="action-reason-id">Reason</Label>
                        <select
                            id="action-reason-id"
                            value={reasonId}
                            onChange={(e) => setReasonId(e.target.value)}
                            className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                        >
                            <option value="">Pick one (optional)</option>
                            {reasons.map((reason) => (
                                <option key={reason.id} value={reason.id}>
                                    {reason.name}
                                </option>
                            ))}
                        </select>
                        <Label htmlFor="action-reason-text" required>
                            In your words
                        </Label>
                        {/* The one full-width control: a reason is prose. */}
                        <textarea
                            id="action-reason-text"
                            aria-required
                            value={reasonText}
                            onChange={(e) => setReasonText(e.target.value)}
                            placeholder="e.g. typed 5,000 for 4,500"
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-20 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {!ready && (
                            <p className="text-muted-foreground text-xs">Say why in at least {MIN_REASON} characters to see what this does.</p>
                        )}
                    </div>

                    {action === 'reverse' && allowToday && (
                        <fieldset className="grid gap-1">
                            <legend className="mb-1 font-medium">Which date</legend>
                            <label className="flex w-fit items-center gap-2">
                                <input
                                    type="radio"
                                    name="date_choice"
                                    checked={dateChoice === 'original'}
                                    onChange={() => setDateChoice('original')}
                                />
                                Its own date (recommended)
                            </label>
                            <label className="flex w-fit items-center gap-2">
                                <input type="radio" name="date_choice" checked={dateChoice === 'today'} onChange={() => setDateChoice('today')} />
                                Today
                            </label>
                        </fieldset>
                    )}

                    {loading && <p className="text-muted-foreground">Working out what this does...</p>}

                    {preview && !loading && (
                        <div className="grid gap-3 rounded-md border p-3">
                            {preview.date && (
                                <p>
                                    <span className="font-medium">Date: </span>
                                    {preview.date}
                                </p>
                            )}
                            {preview.effects.length > 0 && (
                                <div>
                                    <span className="font-medium">Effect: </span>
                                    {preview.effects.join(' ')}
                                </div>
                            )}
                            {preview.stays.length > 0 && (
                                <div>
                                    <span className="font-medium">What stays: </span>
                                    {preview.stays.join(' ')}
                                </div>
                            )}
                            {preview.warnings.map((warning) => (
                                <p key={warning} className="text-destructive">
                                    {warning}
                                </p>
                            ))}
                            {preview.blockers.map((blocker) => (
                                <p key={blocker.code} className="text-destructive">
                                    {blocker.message}{' '}
                                    {blocker.url && (
                                        <a href={blocker.url} className="underline">
                                            Open it
                                        </a>
                                    )}
                                </p>
                            ))}
                        </div>
                    )}

                    {duplicate && duplicate.candidates.length > 0 && (
                        <div className="grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/40">
                            <p className="font-medium">Possible duplicate.</p>
                            {duplicate.candidates.map((candidate, index) => (
                                <p key={`${candidate.number ?? 'hidden'}-${index}`}>
                                    {candidate.summary}
                                    {candidate.saved_by ? ` was saved ${candidate.saved_ago} by ${candidate.saved_by}` : ''}
                                    {candidate.number ? ` (${candidate.number}).` : '.'}{' '}
                                    {candidate.url && (
                                        <a href={candidate.url} target="_blank" rel="noreferrer" className="underline">
                                            Open {candidate.number}
                                        </a>
                                    )}
                                </p>
                            ))}
                            <p>Is this a different record?</p>
                            <div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={!canConfirm}
                                    onClick={() => {
                                        setAck(duplicate.ack_token);
                                        submit(duplicate.ack_token);
                                    }}
                                >
                                    Save anyway
                                </Button>
                            </div>
                        </div>
                    )}

                    {problem && <p className="text-destructive">{problem}</p>}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        Go back
                    </Button>
                    <Button
                        type="button"
                        variant={action === 'reverse' ? 'destructive' : 'default'}
                        disabled={!canConfirm || needsAck}
                        onClick={() => submit()}
                    >
                        {preview?.confirm_label ?? verb}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
