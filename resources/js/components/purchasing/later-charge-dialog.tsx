import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * ⭐ Initial plan B1 (R54, 25 Oct 2026): "additional post-receipt charges the
 * supplier claims later", added from the delivery on the order page. A popup
 * (Joy's rule), date first; the server books it as a landed cost owed to this
 * supplier, with that engine's own approval rules.
 */
export function LaterChargeButton({
    orderId,
    receiptId,
    today,
    types,
}: {
    orderId: number;
    receiptId: number;
    today: string;
    types: { value: string; label: string }[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        incurred_on: today,
        goods_receipt_id: String(receiptId),
        cost_type: types[0]?.value ?? 'freight',
        amount: '',
        description: '',
    });

    const close = (next: boolean) => {
        setOpen(next);

        if (!next) {
            reset();
            clearErrors();
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/purchasing/purchase-orders/${orderId}/later-charges`, { preserveScroll: true, onSuccess: () => close(false) });
    };

    const errorBag = errors as Record<string, string | undefined>;

    return (
        <>
            <button type="button" className="text-primary text-xs hover:underline" onClick={() => setOpen(true)}>
                {t('ipp.later.add')}
            </button>
            <Dialog open={open} onOpenChange={close}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('ipp.later.title')}</DialogTitle>
                        <DialogDescription>{t('ipp.later.intro')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="later_incurred_on" required>
                                {t('ipp.later.date')}
                            </Label>
                            <Input
                                id="later_incurred_on"
                                type="date"
                                max={today}
                                value={data.incurred_on}
                                onChange={(e) => setData('incurred_on', e.target.value)}
                            />
                            <DateWords value={data.incurred_on} />
                            <InputError message={errors.incurred_on} />
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="later_cost_type" required>
                                    {t('ipp.later.type')}
                                </Label>
                                <select
                                    id="later_cost_type"
                                    className="border-input bg-background h-10 w-fit rounded-md border px-2 text-sm"
                                    value={data.cost_type}
                                    onChange={(e) => setData('cost_type', e.target.value)}
                                >
                                    {types.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.cost_type} />
                            </div>
                            <div className="grid w-36 gap-1.5">
                                <Label htmlFor="later_amount" required>
                                    {t('ipp.split.amount')}
                                </Label>
                                <Input
                                    id="later_amount"
                                    inputMode="decimal"
                                    className="text-right tabular-nums"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                />
                                <InputError message={errors.amount} />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="later_description">{t('ipp.later.what')}</Label>
                            <Input
                                id="later_description"
                                className="w-80 max-w-full"
                                maxLength={255}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            <InputError message={errors.description} />
                        </div>
                        <InputError message={errorBag.later_charge ?? errorBag.goods_receipt_id ?? errorBag['lines.0.amount']} />
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => close(false)}>
                                {t('ipp.later.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {t('ipp.later.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
