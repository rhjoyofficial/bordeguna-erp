import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';
import { type AdvanceRow } from './customer-advance-card';

// Whole paisa, so a split adds up exactly.
const toPaisa = (value: string) => Math.round((parseFloat(value) || 0) * 100);
const fromPaisa = (paisa: number) => (paisa / 100).toFixed(2);

/**
 * ⭐ B11 (24 Oct 2026): one money popup (Joy: add and edit in a POPUP, never a
 * form above a list). The fields it is given, date first; one Save button.
 * ⚠ No <form>: Enter never submits, saving happens only by clicking Save.
 */
export function AdvancePopup({
    trigger,
    title,
    description,
    url,
    data,
    fields,
    ready,
    confirmLabel,
    onDone,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    url: string;
    data: Record<string, FormDataConvertible>;
    fields: ReactNode;
    ready: boolean;
    confirmLabel?: string;
    onDone?: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const send = () => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setErrors({});
                onDone?.();
            },
            onError: (problems) => setErrors(problems),
            onFinish: () => setBusy(false),
        });
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
                    {Object.entries(errors).map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('adv.cancel')}
                    </Button>
                    <Button type="button" disabled={!ready || busy} onClick={send}>
                        {busy ? t('adv.working') : (confirmLabel ?? t('adv.save'))}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** A labelled control, as wide as its content. */
export function Field({ id, label, required, children }: { id: string; label: string; required?: boolean; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id} required={required}>
                {label}
            </Label>
            {children}
        </div>
    );
}

export function DateBox({ id, value, max, onChange }: { id: string; value: string; max: string; onChange: (value: string) => void }) {
    return <Input id={id} type="date" className="w-44" max={max} value={value} onChange={(e) => onChange(e.target.value)} />;
}

export function AmountBox({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
    return <Input id={id} inputMode="decimal" className="w-36 text-right" value={value} onChange={(e) => onChange(e.target.value)} />;
}

export function AccountSelect({
    id,
    value,
    accounts,
    onChange,
}: {
    id: string;
    value: string;
    accounts: { id: number; name: string }[];
    onChange: (value: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <SearchSelect id={id} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{t('adv.choose_account')}</option>
            {accounts.map((account) => (
                <option key={account.id} value={account.id}>
                    {account.name}
                </option>
            ))}
        </SearchSelect>
    );
}

/**
 * ⭐ B11: use a customer's advance on one invoice (the invoice page). The
 * oldest advance is filled first up to what the invoice owes; every amount
 * stays editable before anything is saved.
 */
export function UseAdvanceDialog({
    invoice,
    rows,
    owed,
    today,
    earliest,
}: {
    invoice: { id: number; number: string };
    rows: AdvanceRow[];
    owed: string;
    today: string;
    earliest: string;
}) {
    const { t } = useTranslation();
    const proposal = useMemo(() => {
        let left = toPaisa(owed);
        const next: Record<string, string> = {};
        for (const row of rows) {
            const take = Math.max(0, Math.min(left, toPaisa(row.open)));
            next[row.key] = take > 0 ? fromPaisa(take) : '';
            left -= take;
        }
        return next;
    }, [rows, owed]);
    const [amounts, setAmounts] = useState<Record<string, string>>(proposal);
    const [on, setOn] = useState(today < earliest ? earliest : today);
    const total = rows.reduce((sum, row) => sum + toPaisa(amounts[row.key] ?? ''), 0);
    const tooMuch = total > toPaisa(owed);
    const available = rows.reduce((sum, row) => sum + toPaisa(row.open), 0);

    return (
        <AdvancePopup
            trigger={
                <Button size="sm" variant="outline">
                    {t('adv.use')}
                </Button>
            }
            title={t('adv.use_title', { number: invoice.number })}
            description={t('adv.use_text')}
            url={`/sales/invoices/${invoice.id}/advances`}
            data={{ applied_on: on, advances: rows.map((row) => ({ key: row.key, amount: amounts[row.key] ?? '' })) }}
            ready={total > 0 && !tooMuch && on !== ''}
            onDone={() => setAmounts(proposal)}
            fields={
                <>
                    <Field id={`use-advance-on-${invoice.id}`} label={t('adv.date')} required>
                        <DateBox id={`use-advance-on-${invoice.id}`} value={on} max={today} onChange={setOn} />
                    </Field>
                    <p className="text-muted-foreground">{t('adv.use_available', { amount: formatMoney(available / 100) })}</p>
                    <table className="text-sm">
                        <thead>
                            <tr className="text-muted-foreground text-left">
                                <th className="py-1 pr-3">{t('adv.which')}</th>
                                <th className="py-1 pr-3 text-right">{t('adv.open')}</th>
                                <th className="py-1 text-right">{t('adv.amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.key}>
                                    <td className="py-1 pr-3">{row.advance_id === null ? t('adv.on_account') : row.number}</td>
                                    <td className="py-1 pr-3 text-right tabular-nums">{formatMoney(row.open)}</td>
                                    <td className="py-1 text-right">
                                        <AmountBox
                                            id={`use-advance-${invoice.id}-${row.key}`}
                                            value={amounts[row.key] ?? ''}
                                            onChange={(value) => setAmounts((current) => ({ ...current, [row.key]: value }))}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className={tooMuch ? 'text-destructive' : 'text-muted-foreground'}>
                        {tooMuch
                            ? t('adv.use_too_much')
                            : t('adv.use_total', { amount: formatMoney(total / 100), owed: formatMoney(toPaisa(owed) / 100) })}
                    </p>
                </>
            }
        />
    );
}
