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
    // Purchasing approvals (ruling P7, Joy 18 Oct 2026).
    purchasing_approval_at_or_above: string;
    purchasing_approver_roles: string[];
    purchasing_price_tolerance_percent: number;
    purchasing_unusual_discount_percent: number;
    purchasing_approve_non_po_bill: boolean;
    purchasing_approve_price_mismatch: boolean;
    purchasing_approve_unusual_discount: boolean;
    purchasing_approve_advance_over_application: boolean;
    purchasing_approve_new_supplier: boolean;
    purchasing_approve_bank_change: boolean;
};

type SwitchKey =
    | 'purchasing_approve_non_po_bill'
    | 'purchasing_approve_price_mismatch'
    | 'purchasing_approve_unusual_discount'
    | 'purchasing_approve_advance_over_application'
    | 'purchasing_approve_new_supplier'
    | 'purchasing_approve_bank_change';

type NumberKey = Exclude<keyof Policy, 'refuse_negative_money' | 'purchasing_approver_roles' | SwitchKey>;

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
    {
        // Joy, 18 Oct 2026 (ruling P7): below the limit nothing changes; at or
        // above it a second person approves. 0 switches the amount rule off.
        title: 'Purchasing approvals',
        rows: [
            { key: 'purchasing_approval_at_or_above', label: 'Bills and payments need approval at or above', unit: 'Tk' },
            { key: 'purchasing_price_tolerance_percent', label: 'Bill price may be above the order by', unit: '%' },
            { key: 'purchasing_unusual_discount_percent', label: 'A discount is unusual above', unit: '% of the bill' },
        ],
    },
];

export default function FinancePolicy({
    policy,
    updated_at,
    purchasing_switches,
    roles,
}: {
    policy: Policy;
    saved: boolean;
    updated_at: string | null;
    purchasing_switches: { key: SwitchKey; label: string }[];
    roles: string[];
}) {
    const form = useForm<Policy>(policy);

    const toggleRole = (role: string, on: boolean) => {
        const current = form.data.purchasing_approver_roles;
        form.setData('purchasing_approver_roles', on ? [...current, role] : current.filter((r) => r !== role));
    };

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
                        <fieldset
                            key={section.title}
                            // PR6 (18 Oct 2026): Purchases > Setup > Approval limits opens here.
                            id={section.title === 'Purchasing approvals' ? 'approvals' : undefined}
                            className="flex scroll-mt-20 flex-col gap-3"
                        >
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

                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-2 text-sm font-semibold">Who approves purchasing (never the person who entered it)</legend>
                        <div className="flex flex-wrap gap-4">
                            {roles.map((role) => (
                                <label key={role} className="flex cursor-pointer items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.purchasing_approver_roles.includes(role)}
                                        onChange={(event) => toggleRole(role, event.target.checked)}
                                    />
                                    <span>{role}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.purchasing_approver_roles} />
                    </fieldset>

                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-2 text-sm font-semibold">Always needs approval, whatever the amount</legend>
                        {purchasing_switches.map((item) => (
                            <label key={item.key} className="flex cursor-pointer items-center gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data[item.key]}
                                    onChange={(event) => form.setData(item.key, event.target.checked)}
                                />
                                <span>{item.label}</span>
                            </label>
                        ))}
                    </fieldset>

                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
