import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { type FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/**
 * ⭐ Corrections wave 1, purchase documents (19 Oct 2026): the buttons a
 * purchase order or a goods receipt draws from its policy (PurchaseOrder- and
 * GoodsReceiptActionPolicy), and the one reason dialog for the actions that
 * post nothing (Withdraw, Cancel, Fix details, Fix batch details). Reverse and
 * Correct go through ActionDialog, which previews the money and stock effect.
 *
 * ⚠ The server decides everything again: a blocked button is drawn greyed
 * with its sentence (FINAL-PLAN 2, screen rules), and the reason is checked
 * on the server too.
 *
 * ⚠ No <form> element and no key handling (Joy, 19 Oct 2026: no keyboard
 * shortcuts in the ERP): Enter never submits; only the button does.
 */

export type Blocker = { code: string; message: string; url: string | null; requestable: boolean };

export type Availability = {
    action: string;
    label: string;
    allowed: boolean;
    requestable: boolean;
    blockers: Blocker[];
    primary: boolean;
    requires_reason: boolean;
    reason_context: string | null;
    date: { rule: string; sentence: string } | null;
};

export type ActionSetProps = { stage: string; actions: Availability[] };

export type ReasonOption = { id: number; name: string };

const MIN_REASON = 5;

/** A greyed button with its reason, when the policy refused the action. */
export function BlockedAction({ availability, label }: { availability: Availability; label: string }) {
    const why = availability.blockers[0]?.message ?? '';

    return (
        <span className="flex max-w-64 flex-col items-start gap-0.5">
            <Button size="sm" variant="outline" disabled title={why}>
                {label}
            </Button>
            <span className="text-muted-foreground text-[11px] leading-tight">{why}</span>
        </span>
    );
}

type ReasonDialogProps = {
    trigger: ReactNode;
    title: string;
    description: string;
    reasons: ReasonOption[];
    submitUrl: string;
    confirmLabel: string;
    destructive?: boolean;
    /** The action's own fields, drawn FIRST (dates first, Joy 19 Oct). */
    children?: ReactNode;
    /** The action's own data, read at submit. */
    data?: () => Record<string, FormDataConvertible>;
    onDone?: () => void;
};

export function CorrectionReasonDialog({
    trigger,
    title,
    description,
    reasons,
    submitUrl,
    confirmLabel,
    destructive = false,
    children,
    data,
    onDone,
}: ReasonDialogProps) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [reasonId, setReasonId] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const ready = reasonText.trim().length >= MIN_REASON;

    const reset = () => {
        setReasonId('');
        setReasonText('');
        setErrors({});
        setBusy(false);
    };

    const submit = () => {
        setBusy(true);
        router.post(
            submitUrl,
            { ...(data ? data() : {}), reason_id: reasonId === '' ? null : Number(reasonId), reason_text: reasonText.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    reset();
                    onDone?.();
                },
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setBusy(false),
            },
        );
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
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    {children}

                    <div className="grid gap-2">
                        <Label htmlFor="corr-reason-id">{t('corr.reason')}</Label>
                        <select
                            id="corr-reason-id"
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
                        <Label htmlFor="corr-reason-text" required>
                            {t('corr.reason_words')}
                        </Label>
                        <textarea
                            id="corr-reason-text"
                            aria-required
                            value={reasonText}
                            onChange={(e) => setReasonText(e.target.value)}
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-20 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {!ready && <p className="text-muted-foreground text-xs">{t('corr.reason_hint')}</p>}
                    </div>

                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} className="text-destructive">
                            {message}
                        </p>
                    ))}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.go_back')}
                    </Button>
                    <Button type="button" variant={destructive ? 'destructive' : 'default'} disabled={!ready || busy} onClick={submit}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
