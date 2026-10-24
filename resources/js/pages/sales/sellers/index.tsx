import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Initial plan B21 (R109, 24 Oct 2026): every retail seller with his
 * period totals, "who sells how much". Make seller is a popup over the list.
 */
export type SellerTotals = {
    employee_id: number;
    name: string;
    code: string | null;
    stock: { id: number; name: string; is_active: boolean } | null;
    taken: { quantity: string; trade: string; cost: string };
    sold: { quantity: string; value: string; cost: string; margin: string };
    returned: { quantity: string; trade: string; cost: string };
    holding: { quantity: string; cost: string; trade: string };
    money: { collected: string; handed_in: string; cash_held: string; cash_held_minor: number; seller_owes: string; customers_owe: string };
    oldest_days: number | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Retail sellers', href: '/sales/sellers' },
];

export default function SellersIndex({
    filters,
    sellers,
    employees,
}: {
    filters: { from: string; to: string };
    sellers: SellerTotals[];
    employees: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const [period, setPeriod] = useState(filters);
    const [adding, setAdding] = useState(false);
    const form = useForm({ employee_id: '' });

    const save = () => form.post('/sales/sellers', { onSuccess: () => setAdding(false) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('seller.title')} />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('seller.intro')}</p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label className="text-xs">{t('seller.from')}</Label>
                            <Input
                                type="date"
                                className="w-fit"
                                value={period.from}
                                onChange={(e) => setPeriod({ ...period, from: e.target.value })}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label className="text-xs">{t('seller.to')}</Label>
                            <Input type="date" className="w-fit" value={period.to} onChange={(e) => setPeriod({ ...period, to: e.target.value })} />
                        </div>
                        <Button variant="outline" onClick={() => router.get('/sales/sellers', period, { preserveState: true })}>
                            {t('seller.show')}
                        </Button>
                        <Button onClick={() => setAdding(true)}>{t('seller.add')}</Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">{t('seller.name')}</th>
                                <th className="p-3 text-right">{t('seller.taken')}</th>
                                <th className="p-3 text-right">{t('seller.sold')}</th>
                                <th className="p-3 text-right">{t('seller.returned')}</th>
                                <th className="p-3 text-right">{t('seller.holding')}</th>
                                <th className="p-3 text-right">{t('seller.collected')}</th>
                                <th className="p-3 text-right">{t('seller.cash_held')}</th>
                                <th className="p-3 text-right">{t('seller.seller_owes')}</th>
                                <th className="p-3 text-right">{t('seller.oldest')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sellers.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                        {t('seller.none')}
                                    </td>
                                </tr>
                            )}
                            {sellers.map((row) => (
                                <tr key={row.employee_id} className="border-t">
                                    <td className="p-3 font-medium">
                                        <Link
                                            href={`/sales/sellers/${row.employee_id}?from=${period.from}&to=${period.to}`}
                                            className="hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        {row.code && <span className="text-muted-foreground ml-2 text-xs">{row.code}</span>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {row.taken.quantity}
                                        <div className="text-muted-foreground text-xs">
                                            Tk {row.taken.trade} {t('seller.at_tp')}
                                        </div>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {row.sold.quantity}
                                        <div className="text-muted-foreground text-xs">Tk {row.sold.value}</div>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{row.returned.quantity}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {row.holding.quantity}
                                        <div className="text-muted-foreground text-xs">
                                            Tk {row.holding.trade} {t('seller.at_tp')}
                                        </div>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">Tk {row.money.collected}</td>
                                    <td className="p-3 text-right tabular-nums">Tk {row.money.cash_held}</td>
                                    <td className="p-3 text-right tabular-nums">Tk {row.money.seller_owes}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {row.oldest_days === null ? '-' : t('seller.days', { days: row.oldest_days })}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('seller.add')}</DialogTitle>
                        <DialogDescription>{t('seller.add_hint')}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label>{t('seller.employee')}</Label>
                        <SearchableSelect
                            fit
                            value={form.data.employee_id}
                            placeholder={t('seller.pick_employee')}
                            onChange={(value) => form.setData('employee_id', value)}
                            options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
                        />
                        <InputError message={form.errors.employee_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                            {t('seller.cancel')}
                        </Button>
                        <Button type="button" disabled={form.processing || form.data.employee_id === ''} onClick={save}>
                            {t('seller.save')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
