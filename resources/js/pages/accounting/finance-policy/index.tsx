import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Policy = {
    refuse_negative_money: boolean;
    payment_second_approval_above: string;
    advance_refund_owner_above: string;
    cash_payment_limit: string;
    raw_material_cash_limit: string;
    petty_cash_float: string;
    capitalisation_threshold: string;
    staff_purchase_cap_percent: number;
    manager_discount_cap_percent: number;
    staff_discount_cap_percent: number;
    staff_discount_cap: string;
    bad_debt_half_after_days: number;
    bad_debt_full_after_days: number;
    courier_settlement_alert_days: number;
};

type NumberKey = Exclude<keyof Policy, 'refuse_negative_money'>;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Finance policy', href: '/accounting/finance-policy' }];

/* Grouped as the accountant reads them; each row is one owner-editable number. */
const SECTIONS: { title: string; rows: { key: NumberKey; label: string; unit: string }[] }[] = [
    {
        title: 'Payments',
        rows: [
            { key: 'payment_second_approval_above', label: 'Second approval above', unit: 'Tk' },
            { key: 'cash_payment_limit', label: 'Cash payment limit (s.55)', unit: 'Tk' },
            { key: 'raw_material_cash_limit', label: 'Raw material cash limit (s.55)', unit: 'Tk' },
            { key: 'advance_refund_owner_above', label: 'Advance refund needs Owner above', unit: 'Tk' },
        ],
    },
    {
        title: 'Cash and assets',
        rows: [
            { key: 'petty_cash_float', label: 'Petty cash float', unit: 'Tk' },
            { key: 'capitalisation_threshold', label: 'Capitalisation threshold', unit: 'Tk' },
        ],
    },
    {
        title: 'Receivables and staff',
        rows: [
            { key: 'bad_debt_half_after_days', label: '50% bad-debt provision after', unit: 'days' },
            { key: 'bad_debt_full_after_days', label: '100% bad-debt provision after', unit: 'days' },
            { key: 'courier_settlement_alert_days', label: 'Courier payout expected within', unit: 'days' },
            { key: 'staff_purchase_cap_percent', label: 'Staff purchase recovery cap', unit: '% of net pay' },
        ],
    },
    {
        // Joy, 26 Sep 2026. The Owner has no cap. Goods value at list price,
        // without delivery or VAT; promotions are not discretionary.
        title: 'Counter discount caps (per bill)',
        rows: [
            { key: 'manager_discount_cap_percent', label: 'Manager or person in charge', unit: '% of goods' },
            { key: 'staff_discount_cap_percent', label: 'Staff with the override permission', unit: '% of goods' },
            { key: 'staff_discount_cap', label: 'Staff, and never more than', unit: 'Tk' },
        ],
    },
];

export default function FinancePolicy({ policy, updated_at }: { policy: Policy; saved: boolean; updated_at: string | null }) {
    const form = useForm<Policy>(policy);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('accounting.finance-policy.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance policy" />

            <div className="flex flex-col gap-6 p-4">
                {updated_at && <p className="text-muted-foreground text-sm">Last saved {updated_at}</p>}

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-6 rounded-xl border p-4"
                >
                    <label className="flex cursor-pointer items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.refuse_negative_money}
                            onChange={(event) => form.setData('refuse_negative_money', event.target.checked)}
                        />
                        <span className="font-medium">Refuse a payment that takes cash, a wallet or a bank below zero (or past its overdraft)</span>
                    </label>

                    {SECTIONS.map((section) => (
                        <fieldset key={section.title} className="flex flex-col gap-3">
                            <legend className="mb-2 text-sm font-semibold">{section.title}</legend>
                            {section.rows.map((row) => (
                                <div key={row.key} className="flex flex-wrap items-center gap-3">
                                    <Label htmlFor={row.key} className="w-72" required>
                                        {row.label}
                                    </Label>
                                    <Input
                                        id={row.key}
                                        type="number"
                                        min="0"
                                        step={row.unit === 'Tk' ? '0.01' : '1'}
                                        className="w-36"
                                        value={form.data[row.key]}
                                        onChange={(event) =>
                                            form.setData(row.key, (row.unit === 'Tk' ? event.target.value : Number(event.target.value)) as never)
                                        }
                                    />
                                    <span className="text-muted-foreground text-sm">{row.unit}</span>
                                    <InputError message={form.errors[row.key]} />
                                </div>
                            ))}
                        </fieldset>
                    ))}

                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
