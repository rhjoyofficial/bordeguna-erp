import { ActionDialog } from '@/components/action-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { type FormDataConvertible } from '@inertiajs/core';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): the sales documents' correction buttons.
 *
 *   EngineActions    the corrections engine's Reverse and Correct for one
 *                    record (records.actions shape): Reverse opens ActionDialog
 *                    (preview first: date, effect, what stays), Correct goes to
 *                    the record's Correct page. A blocked button stays visible,
 *                    greyed, with its sentence under it (readable on a phone).
 *   ReasonDialog     a plain action that is not a reversal (Cancel an order,
 *                    Close short, Price credit, Extra charge, Re-allocate): the
 *                    fields it needs, a reason of at least 5 characters (the
 *                    server checks again), and one confirm button.
 *
 * ⚠ No keyboard shortcuts (Joy, 19 Oct 2026): nothing here submits on Enter.
 * There is no <form> element; saving happens only by clicking the button.
 */

export type EngineAction = {
    action: 'reverse' | 'correct' | string;
    label: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string; url: string | null; requestable: boolean }[];
    date: { rule: string; sentence: string } | null;
};

export type ReasonOption = { id: number; name: string };

export function EngineActions({
    type,
    id,
    recordLabel,
    actions,
    reasons,
    labels,
    correctHref,
    redirectTo,
}: {
    type: string;
    id: number;
    recordLabel: string;
    actions: EngineAction[];
    reasons: ReasonOption[];
    /** The button words on this screen ("Credit note", "Void sale"). */
    labels: { reverse: string; correct?: string };
    correctHref?: string;
    redirectTo?: string;
}) {
    if (actions.length === 0) {
        return null;
    }

    const base = `/records/${type}/${id}`;
    const blocked = actions
        .filter((option) => !option.allowed)
        .map((option) => option.blockers[0]?.message)
        .filter((message, index, all): message is string => !!message && all.indexOf(message) === index);

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex flex-wrap justify-end gap-2">
                {actions.map((option) => {
                    const usable = option.allowed || option.requestable;
                    const reason = option.blockers[0]?.message;

                    if (option.action === 'correct') {
                        return usable && correctHref ? (
                            <Button key="correct" asChild size="sm" variant="outline">
                                <Link href={correctHref}>{labels.correct ?? option.label}</Link>
                            </Button>
                        ) : (
                            <Button key="correct" size="sm" variant="outline" disabled title={reason}>
                                {labels.correct ?? option.label}
                            </Button>
                        );
                    }

                    return usable ? (
                        <ActionDialog
                            key="reverse"
                            action="reverse"
                            recordLabel={recordLabel}
                            previewUrl={`${base}/reverse/preview`}
                            submitUrl={`${base}/reverse`}
                            reasons={reasons}
                            redirectTo={redirectTo}
                            trigger={
                                <Button size="sm" variant="outline">
                                    {labels.reverse}
                                </Button>
                            }
                        />
                    ) : (
                        <Button key="reverse" size="sm" variant="outline" disabled title={reason}>
                            {labels.reverse}
                        </Button>
                    );
                })}
            </div>
            {blocked.map((message) => (
                <p key={message} className="text-muted-foreground max-w-72 text-right text-xs">
                    {message}
                </p>
            ))}
        </div>
    );
}

/**
 * One plain correction with a reason. `fields` renders above the reason, date
 * boxes first (Joy, 19 Oct 2026); `data` is sent with the reason.
 */
export function ReasonDialog({
    trigger,
    title,
    description,
    url,
    method = 'post',
    reasons = [],
    data = {},
    reasonKey = 'reason_text',
    confirmLabel,
    destructive = false,
    fields,
    ready = true,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    url: string;
    method?: 'post' | 'put';
    reasons?: ReasonOption[];
    data?: Record<string, FormDataConvertible>;
    /** `reason` on the order's Cancel and Close short (their existing field). */
    reasonKey?: 'reason_text' | 'reason';
    confirmLabel: string;
    destructive?: boolean;
    fields?: ReactNode;
    /** False while the fields above are not filled in. */
    ready?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [reasonId, setReasonId] = useState('');
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const enough = text.trim().length >= 5;

    const send = () => {
        setBusy(true);
        router[method](
            url,
            { ...data, [reasonKey]: text.trim(), ...(reasonKey === 'reason_text' && reasonId !== '' ? { reason_id: Number(reasonId) } : {}) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setText('');
                    setReasonId('');
                    setErrors({});
                },
                onError: (problems) => setErrors(problems),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setErrors({});
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    {fields}

                    <div className="grid gap-2">
                        {reasons.length > 0 && reasonKey === 'reason_text' && (
                            <>
                                <Label htmlFor="sales-correction-reason-id">{t('corr.sales.reason')}</Label>
                                <select
                                    id="sales-correction-reason-id"
                                    value={reasonId}
                                    onChange={(e) => setReasonId(e.target.value)}
                                    className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                                >
                                    <option value="">{t('corr.sales.reason_pick')}</option>
                                    {reasons.map((reason) => (
                                        <option key={reason.id} value={reason.id}>
                                            {reason.name}
                                        </option>
                                    ))}
                                </select>
                            </>
                        )}
                        <Label htmlFor="sales-correction-reason" required>
                            {t('corr.sales.reason_words')}
                        </Label>
                        {/* The one full-width control: a reason is prose. */}
                        <textarea
                            id="sales-correction-reason"
                            aria-required
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-20 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {!enough && <p className="text-muted-foreground text-xs">{t('corr.sales.reason_hint')}</p>}
                        <InputError message={errors[reasonKey]} />
                    </div>

                    {Object.entries(errors)
                        .filter(([key]) => key !== reasonKey)
                        .map(([key, message]) => (
                            <p key={key} className="text-destructive">
                                {message}
                            </p>
                        ))}

                    <p className="text-muted-foreground text-xs">{t('corr.sales.stays_note')}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.sales.go_back')}
                    </Button>
                    <Button type="button" variant={destructive ? 'destructive' : 'default'} disabled={!enough || !ready || busy} onClick={send}>
                        {busy ? t('corr.sales.working') : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
