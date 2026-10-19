import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { type FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export type StockAvailability = {
    action: string;
    label: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string; url: string | null; requestable: boolean }[];
    primary: boolean;
    requires_reason: boolean;
    reason_context: string | null;
    approval: string;
    date: { rule: string; sentence: string } | null;
};

const MIN_REASON = 5;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): the dialog for the stock actions the
 * module runs itself (Withdraw, Cancel, Receive part, Delete a draft): a
 * reason picked from the list plus the person's own words (5 characters, the
 * server checks again), any extra boxes as children, and one button.
 *
 * ⚠ No <form>: Joy's rule (19 Oct 2026) is that saving happens only by
 * clicking the button, so Enter in a box never sends it.
 */
export function StockReasonDialog({
    trigger,
    title,
    description,
    confirmLabel,
    reasons,
    url,
    method = 'post',
    data = {},
    destructive = false,
    children,
    ready = true,
}: {
    trigger: ReactNode;
    title: string;
    description?: string;
    confirmLabel: string;
    reasons: { id: number; name: string }[];
    url: string;
    method?: 'post' | 'put' | 'delete';
    data?: Record<string, FormDataConvertible>;
    destructive?: boolean;
    children?: ReactNode;
    /** The extra boxes are filled in (checked again on the server). */
    ready?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [reasonId, setReasonId] = useState('');
    const [text, setText] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const send = () => {
        setBusy(true);
        const body = { ...data, reason_id: reasonId === '' ? null : Number(reasonId), reason_text: text.trim() };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setReasonId('');
                setText('');
                setErrors({});
            },
            onError: (bag: Record<string, string>) => setErrors(bag),
            onFinish: () => setBusy(false),
        };

        if (method === 'delete') {
            router.delete(url, { ...options, data: body });
        } else {
            router[method](url, body, options);
        }
    };

    const firstError = Object.values(errors)[0];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) setErrors({});
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    {children}

                    <div className="grid gap-2">
                        <Label htmlFor="stock-reason-id">{t('corr.stock.reason')}</Label>
                        <select
                            id="stock-reason-id"
                            value={reasonId}
                            onChange={(e) => setReasonId(e.target.value)}
                            className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                        >
                            <option value="">{t('corr.stock.reason_pick')}</option>
                            {reasons.map((reason) => (
                                <option key={reason.id} value={reason.id}>
                                    {reason.name}
                                </option>
                            ))}
                        </select>
                        <Label htmlFor="stock-reason-text" required>
                            {t('corr.stock.reason_words')}
                        </Label>
                        <textarea
                            id="stock-reason-text"
                            aria-required
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-20 w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {text.trim().length < MIN_REASON && <p className="text-muted-foreground text-xs">{t('corr.stock.reason_min')}</p>}
                    </div>

                    <InputError message={firstError} />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.stock.go_back')}
                    </Button>
                    <Button
                        type="button"
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={busy || !ready || text.trim().length < MIN_REASON}
                        onClick={send}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A blocked action stays visible, greyed, with its reason (FINAL-PLAN 6.1). */
export function BlockedNote({ availability }: { availability: StockAvailability | undefined }) {
    if (!availability || availability.allowed || availability.blockers.length === 0) {
        return null;
    }

    return <p className="text-muted-foreground mt-1 max-w-xs text-xs whitespace-normal">{availability.blockers[0].message}</p>;
}
