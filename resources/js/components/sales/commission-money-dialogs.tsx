import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

type MoneyAccounts = { id: number; name: string; type: string }[];

/**
 * ⭐ Settle a commission agreement through a day (25 Oct 2026). The amount is
 * worked out on the server (earned through the day, less what was settled
 * before); the popup only says when, through which day, and how it is paid.
 */
export function SettleCommissionDialog({
    open,
    onOpenChange,
    agreementId,
    number,
    partyType,
    toSettle,
    moneyAccounts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agreementId: number;
    number: string;
    partyType: string;
    toSettle: string | null;
    moneyAccounts: MoneyAccounts;
}) {
    const { t } = useTranslation();
    const blank = () => ({ settled_on: dhakaToday(), through_date: dhakaToday(), method: 'payable', money_account_id: '', note: '' });
    const { data, setData, post, errors, processing, clearErrors, setDefaults } = useForm(blank());

    useEffect(() => {
        if (open) {
            const next = blank();
            setDefaults(next);
            setData(next);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        clearErrors();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/sales/commission-agreements/${agreementId}/settle`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{t('commission.settle_title', { number })}</DialogTitle>
                        <DialogDescription>{t('commission.settle_hint')}</DialogDescription>
                    </DialogHeader>

                    {toSettle !== null && <p className="text-sm font-medium">{t('commission.waiting_now', { amount: formatMoney(toSettle) })}</p>}

                    <FormRow>
                        <Field label={t('commission.settled_on')} htmlFor="cs-settled_on" error={errors.settled_on} required>
                            <Input
                                id="cs-settled_on"
                                type="date"
                                max={dhakaToday()}
                                value={data.settled_on}
                                onChange={(e) => setData('settled_on', e.target.value)}
                            />
                        </Field>
                        <Field label={t('commission.through')} htmlFor="cs-through" error={errors.through_date} required>
                            <Input
                                id="cs-through"
                                type="date"
                                max={data.settled_on}
                                value={data.through_date}
                                onChange={(e) => setData('through_date', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label={t('commission.method')} htmlFor="cs-method" error={errors.method} required>
                            <Select value={data.method} onValueChange={(value) => setData('method', value)}>
                                <SelectTrigger id="cs-method">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="payable">{t('commission.method_payable')}</SelectItem>
                                    <SelectItem value="paid_now">{t('commission.method_paid_now')}</SelectItem>
                                    {partyType === 'employee' && <SelectItem value="payroll">{t('commission.method_payroll')}</SelectItem>}
                                </SelectContent>
                            </Select>
                        </Field>
                        {data.method === 'paid_now' && (
                            <Field label={t('commission.money_account')} htmlFor="cs-money" error={errors.money_account_id} required>
                                <Select value={data.money_account_id} onValueChange={(value) => setData('money_account_id', value)}>
                                    <SelectTrigger id="cs-money">
                                        <SelectValue placeholder={t('commission.money_account')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {moneyAccounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                    </FormRow>

                    <Field label={t('commission.note')} htmlFor="cs-note" error={errors.note}>
                        <textarea
                            id="cs-note"
                            rows={2}
                            className="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </Field>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('commission.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {t('commission.settle_now')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Pay commission a settlement left owed, never more than it still owes (the server re-checks under a lock). */
export function PayCommissionDialog({
    open,
    onOpenChange,
    settlementId,
    number,
    owed,
    moneyAccounts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    settlementId: number;
    number: string;
    owed: string;
    moneyAccounts: MoneyAccounts;
}) {
    const { t } = useTranslation();
    const blank = () => ({ paid_on: dhakaToday(), amount: owed, money_account_id: '', note: '' });
    const { data, setData, post, errors, processing, clearErrors, setDefaults } = useForm(blank());

    useEffect(() => {
        if (open) {
            const next = blank();
            setDefaults(next);
            setData(next);
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, settlementId]);

    const close = () => {
        clearErrors();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/sales/commission-settlements/${settlementId}/pay`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{t('commission.pay_title', { number })}</DialogTitle>
                        <DialogDescription>
                            {t('commission.owed')}: Tk {formatMoney(owed)}
                        </DialogDescription>
                    </DialogHeader>

                    <FormRow>
                        <Field label={t('commission.paid_on')} htmlFor="cp-paid_on" error={errors.paid_on} required>
                            <Input
                                id="cp-paid_on"
                                type="date"
                                max={dhakaToday()}
                                value={data.paid_on}
                                onChange={(e) => setData('paid_on', e.target.value)}
                            />
                        </Field>
                        <Field label={t('commission.amount')} htmlFor="cp-amount" size="sm" error={errors.amount} required>
                            <Input
                                id="cp-amount"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                            />
                        </Field>
                        <Field label={t('commission.money_account')} htmlFor="cp-money" error={errors.money_account_id} required>
                            <Select value={data.money_account_id} onValueChange={(value) => setData('money_account_id', value)}>
                                <SelectTrigger id="cp-money">
                                    <SelectValue placeholder={t('commission.money_account')} />
                                </SelectTrigger>
                                <SelectContent>
                                    {moneyAccounts.map((account) => (
                                        <SelectItem key={account.id} value={String(account.id)}>
                                            {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </FormRow>

                    <Field label={t('commission.note')} htmlFor="cp-note" error={errors.note}>
                        <textarea
                            id="cp-note"
                            rows={2}
                            className="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </Field>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('commission.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {t('commission.pay_now')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
