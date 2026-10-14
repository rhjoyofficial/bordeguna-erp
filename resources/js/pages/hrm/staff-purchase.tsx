import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The staff side of the Staff Product Benefit (BDG/HR/09/01/2026).
 * Staff see ONLY the staff price and the MRP-counted allowance - never a
 * trade price. One unit per SKU per month; the store hands over after
 * approval.
 */
interface BenefitProduct {
    id: number;
    name: string;
    sku: string | null;
    staff_price: number;
    mrp: number;
}

interface RequestRow {
    id: number;
    number: string;
    payment_mode: string;
    status: string;
    decision_note: string | null;
    applied_at: string;
    total_staff_price: number;
    lines: { product: string | null; staff_price: number; mrp: number }[];
}

interface StaffPurchaseProps {
    products: BenefitProduct[];
    allowance: { limit: number; used: number };
    requests: RequestRow[];
}

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

// HRM-007 (14 Oct 2026): the four states and the four ways to pay, as
// dictionary keys so a Bangla-first employee reads them in Bangla.
const statusKey: Record<string, TranslationKey> = {
    pending: 'staff_purchase.status_pending',
    approved: 'staff_purchase.status_approved',
    fulfilled: 'staff_purchase.status_fulfilled',
    rejected: 'staff_purchase.status_rejected',
};

const payKey: Record<string, TranslationKey> = {
    cash: 'staff_purchase.pay_cash',
    mfs: 'staff_purchase.pay_mfs',
    bank: 'staff_purchase.pay_bank',
    salary_deduction: 'staff_purchase.pay_salary_deduction',
};

export default function StaffPurchase({ products, allowance, requests }: StaffPurchaseProps) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('staff_purchase.title'), href: '/hrm/staff-purchase' }];
    const [selected, setSelected] = useState<number[]>([]);
    const form = useForm({ payment_mode: 'cash', lines: [] as { product_id: number }[] });

    const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const mrpOfSelection = products.filter((p) => selected.includes(p.id)).reduce((sum, p) => sum + p.mrp, 0);
    const remaining = allowance.limit - allowance.used;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('staff_purchase.title')} />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{t('staff_purchase.heading')}</h1>
                </div>

                <div className="flex flex-wrap gap-4">
                    <div className="border-border bg-card rounded-lg border px-4 py-3">
                        <p className="text-muted-foreground text-xs">{t('staff_purchase.allowance')}</p>
                        <p className="text-lg font-semibold tabular-nums">
                            {money(allowance.used)} / {money(allowance.limit)}
                        </p>
                    </div>
                    {selected.length > 0 && (
                        <div
                            className={
                                'rounded-lg border px-4 py-3 ' +
                                (mrpOfSelection > remaining ? 'border-destructive/40 bg-destructive/5' : 'border-emerald-500/40 bg-emerald-500/5')
                            }
                        >
                            <p className="text-muted-foreground text-xs">{t('staff_purchase.selection_uses')}</p>
                            <p className="text-lg font-semibold tabular-nums">{money(mrpOfSelection)}</p>
                        </div>
                    )}
                </div>

                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {products.map((product) => (
                        <label
                            key={product.id}
                            className={
                                'flex cursor-pointer items-start gap-2 rounded-lg border p-3 ' +
                                (selected.includes(product.id) ? 'border-primary bg-primary/5' : 'border-border bg-card')
                            }
                        >
                            <input type="checkbox" className="mt-1" checked={selected.includes(product.id)} onChange={() => toggle(product.id)} />
                            <span>
                                <span className="block text-sm font-medium">{product.name}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {t('staff_purchase.staff_price')} <strong>{money(product.staff_price)}</strong> · {t('staff_purchase.mrp')}{' '}
                                    {money(product.mrp)}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <p className="text-muted-foreground text-xs">
                            {t('staff_purchase.how_pay')}
                            <span className="text-destructive ml-0.5 font-semibold">*</span>
                        </p>
                        <Select value={form.data.payment_mode} onValueChange={(value) => form.setData('payment_mode', value)}>
                            <SelectTrigger className="w-56" aria-required>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cash">{t('staff_purchase.pay_cash')}</SelectItem>
                                <SelectItem value="mfs">{t('staff_purchase.pay_mfs')}</SelectItem>
                                <SelectItem value="bank">{t('staff_purchase.pay_bank')}</SelectItem>
                                <SelectItem value="salary_deduction">{t('staff_purchase.pay_salary_deduction')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        disabled={selected.length === 0 || form.processing}
                        onClick={() => {
                            form.transform((data) => ({
                                payment_mode: data.payment_mode,
                                lines: selected.map((id) => ({ product_id: id })),
                            }));
                            form.post('/hrm/staff-purchase', { preserveScroll: true, onSuccess: () => setSelected([]) });
                        }}
                    >
                        {selected.length === 0
                            ? t('staff_purchase.apply_none')
                            : t(selected.length === 1 ? 'staff_purchase.apply_one' : 'staff_purchase.apply_many', { count: selected.length })}
                    </Button>
                    {form.errors.lines && <p className="text-destructive w-full text-sm">{form.errors.lines}</p>}
                </div>

                <div className="space-y-2">
                    <h2 className="font-semibold">{t('staff_purchase.my_applications')}</h2>
                    {requests.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('staff_purchase.none_yet')}</p>
                    ) : (
                        requests.map((row) => (
                            <div key={row.id} className="border-border bg-card rounded-lg border p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-medium">
                                        {row.number}
                                        <span className="text-muted-foreground font-normal"> · {row.applied_at?.slice(0, 10)}</span>
                                    </p>
                                    <span
                                        className={
                                            'rounded-full px-2 py-0.5 text-xs font-medium ' +
                                            (row.status === 'fulfilled'
                                                ? 'bg-emerald-500/10 text-emerald-700'
                                                : row.status === 'rejected'
                                                  ? 'bg-destructive/10 text-destructive'
                                                  : 'bg-muted text-muted-foreground')
                                        }
                                    >
                                        {statusKey[row.status] ? t(statusKey[row.status]) : row.status}
                                    </span>
                                </div>
                                <p className="text-muted-foreground">
                                    {row.lines.map((line) => line.product).join(', ')} ·{' '}
                                    {t('staff_purchase.pays', { amount: money(row.total_staff_price) })} (
                                    {payKey[row.payment_mode] ? t(payKey[row.payment_mode]) : row.payment_mode})
                                </p>
                                {row.decision_note && <p className="text-destructive text-xs">{row.decision_note}</p>}
                            </div>
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
