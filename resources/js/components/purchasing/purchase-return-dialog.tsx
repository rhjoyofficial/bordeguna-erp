import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export type ReturnFormLine = {
    purchase_order_line_id: number;
    product: string | null;
    unit: string | null;
    batches: string[];
    can_return: string;
    price: string;
    landed_per_unit: string;
    unbilled: string;
    used_up: boolean;
};

export type ReturnForm = {
    order_id: number;
    po_number: string;
    supplier: string | null;
    today: string;
    earliest: string | null;
    lines: ReturnFormLine[];
    bills_owe: string;
    can_credit: boolean;
    can_refund: boolean;
    money_accounts: { id: number; name: string; balance?: string | null }[];
};

type RefundRow = { money_account_id: string; amount: string };

const newKey = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;

/** Money and quantities typed in a box, as whole paisa / ten-thousandths. */
const paisa = (value: string | number): number => Math.round((Number(value) || 0) * 100);
const tenK = (value: string | number): number => Math.round((Number(value) || 0) * 10000);

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): "Return" on the
 * goods receipt and the supplier bill. A popup, date first. The goods go back
 * from that delivery's own batches; the preview says what the supplier will
 * owe back, how much of it reduces the open bill, and, when the bill was
 * already paid, lets the user keep the rest as an advance for the next
 * purchase or record the supplier paying it back (split across accounts).
 *
 * ⚠ The preview is arithmetic on the page; the server works every figure out
 * again in one transaction and is the authority.
 */
export function PurchaseReturnButton({ form, url }: { form: ReturnForm; url: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const blocked = form.lines.every((line) => line.used_up);

    const initial = () => ({
        returned_on: form.today,
        reason: '',
        request_key: newKey(),
        lines: form.lines.map((line) => ({ purchase_order_line_id: line.purchase_order_line_id, quantity: '' })),
        settle: 'advance' as 'advance' | 'refund',
        refunds: [{ money_account_id: '', amount: '' }] as RefundRow[],
        credit_note_number: '',
    });

    const { data, setData, post, processing, errors, clearErrors, transform } = useForm(initial());
    const errorBag = errors as Record<string, string | undefined>;

    const close = (next: boolean) => {
        setOpen(next);

        if (next) {
            setData(initial());
        } else {
            clearErrors();
        }
    };

    // The preview, line by line.
    let valuePaisa = 0;
    let billedPaisa = 0;
    let landedPaisa = 0;

    form.lines.forEach((line, index) => {
        const quantity = Number(data.lines[index]?.quantity) || 0;
        const billed = Math.max(0, tenK(quantity) - tenK(line.unbilled)) / 10000;

        valuePaisa += paisa(quantity * Number(line.price));
        billedPaisa += paisa(billed * Number(line.price));
        landedPaisa += paisa(quantity * Number(line.landed_per_unit));
    });

    const reducesBill = Math.min(billedPaisa, paisa(form.bills_owe));
    const paidBack = billedPaisa - reducesBill;
    const refundRows = data.refunds.filter((row) => row.money_account_id !== '' && paisa(row.amount) > 0);
    const refundedPaisa = refundRows.reduce((sum, row) => sum + paisa(row.amount), 0);

    const setQuantity = (index: number, quantity: string) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, quantity } : line)),
        );

    const setRefund = (index: number, patch: Partial<RefundRow>) =>
        setData(
            'refunds',
            data.refunds.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((payload) => ({
            ...payload,
            settle: paidBack > 0 ? payload.settle : 'advance',
            refunds: paidBack > 0 && payload.settle === 'refund' ? refundRows : [],
        }));
        post(url, { preserveScroll: true, onSuccess: () => close(false) });
    };

    if (blocked) {
        return (
            <Button size="sm" variant="outline" disabled title={t('pret.used_up')}>
                {t('pret.button')}
            </Button>
        );
    }

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => close(true)}>
                {t('pret.button')}
            </Button>
            <Dialog open={open} onOpenChange={close}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{t('pret.title', { po: form.po_number })}</DialogTitle>
                        <DialogDescription>{t('pret.intro')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="return_on" required>
                                {t('pret.date')}
                            </Label>
                            <Input
                                id="return_on"
                                type="date"
                                className="w-fit"
                                min={form.earliest ?? undefined}
                                max={form.today}
                                value={data.returned_on}
                                onChange={(e) => setData('returned_on', e.target.value)}
                            />
                            <DateWords value={data.returned_on} />
                            <InputError message={errors.returned_on} />
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2">{t('pret.col_item')}</th>
                                        <th className="p-2">{t('pret.col_batch')}</th>
                                        <th className="p-2 text-right">{t('pret.col_can')}</th>
                                        <th className="p-2 text-right">{t('pret.col_qty')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {form.lines.map((line, index) => (
                                        <tr key={line.purchase_order_line_id} className="border-t">
                                            <td className="p-2">
                                                {line.product}
                                                {Number(line.landed_per_unit) > 0 && (
                                                    <p className="text-muted-foreground text-xs">
                                                        {t('pret.landed_hint', { amount: formatMoney(line.landed_per_unit), unit: line.unit ?? '' })}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-2 text-xs">{line.batches.join(', ')}</td>
                                            <td className="p-2 text-right tabular-nums">
                                                {line.used_up ? t('pret.none_left') : `${line.can_return} ${line.unit ?? ''}`}
                                            </td>
                                            <td className="p-2 text-right">
                                                <Input
                                                    inputMode="decimal"
                                                    aria-label={t('pret.col_qty')}
                                                    className="ml-auto w-28 text-right tabular-nums"
                                                    disabled={line.used_up}
                                                    value={data.lines[index]?.quantity ?? ''}
                                                    onChange={(e) => setQuantity(index, e.target.value)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <InputError message={errorBag.lines} />

                        <div className="grid gap-1.5">
                            <Label htmlFor="return_reason" required>
                                {t('pret.reason')}
                            </Label>
                            <textarea
                                id="return_reason"
                                className="border-input bg-background min-h-16 rounded-md border px-3 py-2 text-sm"
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                            />
                            <InputError message={errors.reason} />
                        </div>

                        <dl className="bg-muted/30 grid grid-cols-[auto_auto] justify-start gap-x-6 gap-y-1 rounded-lg border p-3 text-sm">
                            <dt className="text-muted-foreground">{t('pret.value')}</dt>
                            <dd className="text-right tabular-nums">{formatMoney(valuePaisa / 100)}</dd>
                            <dt className="text-muted-foreground">{t('pret.reduces_bill')}</dt>
                            <dd className="text-right tabular-nums">{formatMoney(reducesBill / 100)}</dd>
                            <dt className="text-muted-foreground">{t('pret.paid_back')}</dt>
                            <dd className="text-right font-medium tabular-nums">{formatMoney(paidBack / 100)}</dd>
                            {landedPaisa > 0 && (
                                <>
                                    <dt className="text-muted-foreground">{t('pret.landed_loss')}</dt>
                                    <dd className="text-right tabular-nums">{formatMoney(landedPaisa / 100)}</dd>
                                </>
                            )}
                        </dl>
                        {valuePaisa > 0 && billedPaisa === 0 && <p className="text-muted-foreground text-xs">{t('pret.unbilled_hint')}</p>}
                        {billedPaisa > 0 && !form.can_credit && <p className="text-destructive text-xs">{t('pret.no_credit_permission')}</p>}

                        {reducesBill > 0 && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="return_cn">{t('pret.cn_number')}</Label>
                                <Input
                                    id="return_cn"
                                    className="w-48"
                                    maxLength={60}
                                    value={data.credit_note_number}
                                    onChange={(e) => setData('credit_note_number', e.target.value)}
                                />
                                <InputError message={errors.credit_note_number} />
                            </div>
                        )}

                        {paidBack > 0 && (
                            <fieldset className="flex flex-col gap-2 rounded-lg border p-3">
                                <legend className="px-1 text-sm font-medium">
                                    {t('pret.settle_title', { amount: formatMoney(paidBack / 100) })}
                                </legend>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="settle"
                                        checked={data.settle === 'advance'}
                                        onChange={() => setData('settle', 'advance')}
                                    />
                                    {t('pret.settle_advance')}
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="settle"
                                        disabled={!form.can_refund}
                                        checked={data.settle === 'refund'}
                                        onChange={() => setData('settle', 'refund')}
                                    />
                                    {t('pret.settle_refund')}
                                    {!form.can_refund && <span className="text-muted-foreground text-xs">{t('pret.no_refund_permission')}</span>}
                                </label>
                                {data.settle === 'refund' && (
                                    <div className="flex flex-col gap-2 pl-6">
                                        {data.refunds.map((row, index) => (
                                            <div key={index} className="flex flex-wrap items-center gap-2">
                                                <select
                                                    aria-label={t('pret.refund_into')}
                                                    className="border-input bg-background h-9 w-fit rounded-md border px-2 text-sm"
                                                    value={row.money_account_id}
                                                    onChange={(e) => setRefund(index, { money_account_id: e.target.value })}
                                                >
                                                    <option value="">{t('pret.refund_into')}</option>
                                                    {form.money_accounts.map((account) => (
                                                        <option key={account.id} value={String(account.id)}>
                                                            {account.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <Input
                                                    inputMode="decimal"
                                                    aria-label={t('pret.refund_amount')}
                                                    className="w-32 text-right tabular-nums"
                                                    value={row.amount}
                                                    onChange={(e) => setRefund(index, { amount: e.target.value })}
                                                />
                                                {data.refunds.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={t('pret.refund_remove')}
                                                        onClick={() =>
                                                            setData(
                                                                'refunds',
                                                                data.refunds.filter((_, i) => i !== index),
                                                            )
                                                        }
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-fit"
                                            onClick={() => setData('refunds', [...data.refunds, { money_account_id: '', amount: '' }])}
                                        >
                                            <Plus className="size-4" /> {t('pret.refund_add')}
                                        </Button>
                                        <p className="text-muted-foreground text-xs">
                                            {t('pret.refund_left', { amount: formatMoney(Math.max(0, paidBack - refundedPaisa) / 100) })}
                                        </p>
                                    </div>
                                )}
                                <InputError message={errorBag.refunds ?? errorBag.settle} />
                            </fieldset>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => close(false)}>
                                {t('pret.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing || valuePaisa <= 0}>
                                {t('pret.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
