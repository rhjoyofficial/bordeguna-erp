import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { RecordHistory } from '@/components/record-history';
import { AccountSelect, AdvancePopup, AmountBox, DateBox, Field } from '@/components/sales/advance-dialogs';
import { EngineActions, type EngineAction, type ReasonOption } from '@/components/sales/correction-actions';
import { type AdvanceRow } from '@/components/sales/customer-advance-card';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface Advance {
    id: number;
    number: string;
    kind: 'receipt' | 'from_order';
    received_on: string;
    customer: string | null;
    customer_id: number;
    money_account: string | null;
    order: { id: number; order_number: string } | null;
    reference: string | null;
    amount: string;
    open: string;
    reversed: boolean;
    actions: EngineAction[];
}

interface Use {
    id: number;
    number: string;
    applied_on: string;
    customer: string | null;
    invoice: { id: number; invoice_number: string } | null;
    from: string;
    amount: string;
    reversed: boolean;
    actions: EngineAction[];
}

interface Refund {
    id: number;
    number: string;
    refunded_on: string;
    customer: string | null;
    money_account: string | null;
    from: string;
    amount: string;
    reversed: boolean;
    actions: EngineAction[];
}

type Account = { id: number; name: string };

/**
 * ⭐ Customer advances (Joy's initial plan R90, B11, 24 Oct 2026): every
 * advance with what it still has open, where each was used and what went
 * back, each with Reverse (the corrections engine) and History. Receive an
 * advance and Pay one back open in a popup, date first.
 */
export default function AdvancesIndex({
    filters: initialFilters,
    customer,
    advances,
    uses,
    refunds,
    customers,
    moneyAccounts,
    canReceive,
    canRefund,
    reversalReasons,
    today,
}: {
    filters: { customer_id: string };
    customer: { id: number; name: string; with_us: string; rows: AdvanceRow[] } | null;
    advances: Paginated<Advance>;
    uses: Use[];
    refunds: Refund[];
    customers: { id: number; name: string }[];
    moneyAccounts: Account[];
    canReceive: boolean;
    canRefund: boolean;
    reversalReasons: ReasonOption[];
    today: string;
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/sales/advances', initialFilters);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: t('adv.title'), href: '/sales/advances' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('adv.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{t('adv.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('adv.intro')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canReceive && moneyAccounts.length > 0 && (
                            <ReceiveAdvance customers={customers} accounts={moneyAccounts} today={today} preset={customer?.id ?? null} />
                        )}
                        {canRefund && customer && customer.rows.length > 0 && moneyAccounts.length > 0 && (
                            <RefundAdvance customer={customer} accounts={moneyAccounts} today={today} />
                        )}
                    </div>
                </div>

                <FilterBar onClear={reset} showClear={hasActiveFilters}>
                    <FilterField label={t('adv.customer')} htmlFor="advances-customer" className="min-w-56">
                        <SearchSelect id="advances-customer" value={filters.customer_id} onChange={(e) => setFilter('customer_id', e.target.value)}>
                            <option value="">{t('adv.all_customers')}</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                {customer && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card w-fit rounded-xl border p-4">
                        <p className="text-muted-foreground text-xs">
                            {t('adv.with_us')} · {customer.name}
                        </p>
                        <p className="mt-1 text-xl font-semibold tabular-nums">{customer.with_us}</p>
                        {customer.rows.length === 0 ? (
                            <p className="text-muted-foreground text-xs">{t('adv.none_open')}</p>
                        ) : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {customer.rows.map((row) => (
                                    <li key={row.key} className="flex justify-between gap-6">
                                        <span>
                                            {row.advance_id === null ? t('adv.on_account') : row.number}{' '}
                                            <span className="text-muted-foreground text-xs">{showDate(row.date)}</span>
                                        </span>
                                        <span className="tabular-nums">{formatMoney(row.open)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                <section className="grid gap-2">
                    <h2 className="text-sm font-semibold">{t('adv.list')}</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('adv.date')}</th>
                                    <th className="p-3">{t('adv.number')}</th>
                                    <th className="p-3">{t('adv.customer')}</th>
                                    <th className="p-3">{t('adv.kind')}</th>
                                    <th className="p-3 text-right">{t('adv.amount')}</th>
                                    <th className="p-3 text-right">{t('adv.open')}</th>
                                    <th className="p-3 text-right">{t('adv.actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {advances.data.map((advance) => (
                                    <tr key={advance.id} className="border-t align-top">
                                        <td className="p-3 whitespace-nowrap">{showDate(advance.received_on)}</td>
                                        <td className="p-3 font-mono">
                                            {advance.number}
                                            {advance.reversed && (
                                                <Badge variant="secondary" className="ml-2">
                                                    {t('adv.reversed')}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <Link href={`/sales/customers/${advance.customer_id}`} className="underline-offset-2 hover:underline">
                                                {advance.customer}
                                            </Link>
                                        </td>
                                        <td className="p-3">
                                            {advance.kind === 'from_order' ? (
                                                <>
                                                    {t('adv.kind_from_order')}{' '}
                                                    {advance.order && (
                                                        <Link
                                                            href={`/sales/orders/${advance.order.id}`}
                                                            className="underline-offset-2 hover:underline"
                                                        >
                                                            {advance.order.order_number}
                                                        </Link>
                                                    )}
                                                </>
                                            ) : (
                                                <>
                                                    {t('adv.kind_receipt')}
                                                    {advance.money_account && (
                                                        <span className="text-muted-foreground"> · {advance.money_account}</span>
                                                    )}
                                                </>
                                            )}
                                            {advance.reference && <div className="text-muted-foreground text-xs">{advance.reference}</div>}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(advance.amount)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(advance.open)}</td>
                                        <td className="p-3">
                                            <RowActions
                                                type="sales.customer_advance"
                                                id={advance.id}
                                                label={advance.number}
                                                actions={advance.actions}
                                                reasons={reversalReasons}
                                            />
                                        </td>
                                    </tr>
                                ))}
                                {advances.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                            {t('adv.empty')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={advances} />
                </section>

                {uses.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">{t('adv.uses')}</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">{t('adv.date')}</th>
                                        <th className="p-3">{t('adv.number')}</th>
                                        <th className="p-3">{t('adv.customer')}</th>
                                        <th className="p-3">{t('adv.invoice')}</th>
                                        <th className="p-3">{t('adv.from')}</th>
                                        <th className="p-3 text-right">{t('adv.amount')}</th>
                                        <th className="p-3 text-right">{t('adv.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {uses.map((use) => (
                                        <tr key={use.id} className="border-t align-top">
                                            <td className="p-3 whitespace-nowrap">{showDate(use.applied_on)}</td>
                                            <td className="p-3 font-mono">
                                                {use.number}
                                                {use.reversed && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        {t('adv.reversed')}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="p-3">{use.customer}</td>
                                            <td className="p-3">
                                                {use.invoice && (
                                                    <Link href={`/sales/invoices/${use.invoice.id}`} className="underline-offset-2 hover:underline">
                                                        {use.invoice.invoice_number}
                                                    </Link>
                                                )}
                                            </td>
                                            <td className="p-3">{use.from === 'On account' ? t('adv.on_account') : use.from}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(use.amount)}</td>
                                            <td className="p-3">
                                                <RowActions
                                                    type="sales.customer_advance_application"
                                                    id={use.id}
                                                    label={use.number}
                                                    actions={use.actions}
                                                    reasons={reversalReasons}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {refunds.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">{t('adv.refunds')}</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">{t('adv.date')}</th>
                                        <th className="p-3">{t('adv.number')}</th>
                                        <th className="p-3">{t('adv.customer')}</th>
                                        <th className="p-3">{t('adv.account_out')}</th>
                                        <th className="p-3">{t('adv.from')}</th>
                                        <th className="p-3 text-right">{t('adv.amount')}</th>
                                        <th className="p-3 text-right">{t('adv.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {refunds.map((refund) => (
                                        <tr key={refund.id} className="border-t align-top">
                                            <td className="p-3 whitespace-nowrap">{showDate(refund.refunded_on)}</td>
                                            <td className="p-3 font-mono">
                                                {refund.number}
                                                {refund.reversed && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        {t('adv.reversed')}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="p-3">{refund.customer}</td>
                                            <td className="p-3">{refund.money_account}</td>
                                            <td className="p-3">{refund.from === 'On account' ? t('adv.on_account') : refund.from}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(refund.amount)}</td>
                                            <td className="p-3">
                                                <RowActions
                                                    type="sales.customer_advance_refund"
                                                    id={refund.id}
                                                    label={refund.number}
                                                    actions={refund.actions}
                                                    reasons={reversalReasons}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function RowActions({
    type,
    id,
    label,
    actions,
    reasons,
}: {
    type: string;
    id: number;
    label: string;
    actions: EngineAction[];
    reasons: ReasonOption[];
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col items-end gap-1">
            <EngineActions type={type} id={id} recordLabel={label} actions={actions} reasons={reasons} labels={{ reverse: t('adv.reverse') }} />
            <RecordHistory type={type} id={id} label={label} />
        </div>
    );
}

function ReceiveAdvance({
    customers,
    accounts,
    today,
    preset,
}: {
    customers: { id: number; name: string }[];
    accounts: Account[];
    today: string;
    preset: number | null;
}) {
    const { t } = useTranslation();
    const empty = {
        received_on: today,
        customer_id: preset ? String(preset) : '',
        money_account_id: accounts.length === 1 ? String(accounts[0].id) : '',
        amount: '',
        reference: '',
        note: '',
    };
    const [data, setData] = useState(empty);
    const set = (key: keyof typeof empty) => (value: string) => setData((current) => ({ ...current, [key]: value }));

    return (
        <AdvancePopup
            trigger={<Button size="sm">{t('adv.receive')}</Button>}
            title={t('adv.receive_title')}
            description={t('adv.receive_text')}
            url="/sales/advances"
            data={data}
            ready={data.customer_id !== '' && data.money_account_id !== '' && Number(data.amount) > 0 && data.received_on !== ''}
            onDone={() => setData(empty)}
            fields={
                <>
                    <Field id="advance-received-on" label={t('adv.date')} required>
                        <DateBox id="advance-received-on" value={data.received_on} max={today} onChange={set('received_on')} />
                    </Field>
                    <Field id="advance-customer" label={t('adv.customer')} required>
                        <SearchSelect id="advance-customer" value={data.customer_id} onChange={(e) => set('customer_id')(e.target.value)}>
                            <option value="">{t('adv.choose_customer')}</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field id="advance-account" label={t('adv.account_in')} required>
                        <AccountSelect id="advance-account" value={data.money_account_id} accounts={accounts} onChange={set('money_account_id')} />
                    </Field>
                    <Field id="advance-amount" label={t('adv.amount')} required>
                        <AmountBox id="advance-amount" value={data.amount} onChange={set('amount')} />
                    </Field>
                    <Field id="advance-reference" label={t('adv.reference')}>
                        <Input id="advance-reference" className="w-56" value={data.reference} onChange={(e) => set('reference')(e.target.value)} />
                    </Field>
                    <Field id="advance-note" label={t('adv.note')}>
                        <textarea
                            id="advance-note"
                            value={data.note}
                            onChange={(e) => set('note')(e.target.value)}
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        />
                    </Field>
                </>
            }
        />
    );
}

function RefundAdvance({ customer, accounts, today }: { customer: { id: number; rows: AdvanceRow[] }; accounts: Account[]; today: string }) {
    const { t } = useTranslation();
    const empty = {
        refunded_on: today,
        customer_id: String(customer.id),
        key: customer.rows[0]?.key ?? '',
        money_account_id: '',
        amount: '',
        reference: '',
        note: '',
    };
    const [data, setData] = useState(empty);
    const set = (key: keyof typeof empty) => (value: string) => setData((current) => ({ ...current, [key]: value }));

    return (
        <AdvancePopup
            trigger={
                <Button size="sm" variant="outline">
                    {t('adv.refund')}
                </Button>
            }
            title={t('adv.refund_title')}
            description={t('adv.refund_text')}
            url="/sales/advances/refunds"
            data={data}
            ready={data.key !== '' && data.money_account_id !== '' && Number(data.amount) > 0 && data.refunded_on !== ''}
            onDone={() => setData(empty)}
            fields={
                <>
                    <Field id="advance-refunded-on" label={t('adv.date')} required>
                        <DateBox id="advance-refunded-on" value={data.refunded_on} max={today} onChange={set('refunded_on')} />
                    </Field>
                    <Field id="advance-refund-which" label={t('adv.which')} required>
                        <SearchSelect id="advance-refund-which" value={data.key} onChange={(e) => set('key')(e.target.value)}>
                            {customer.rows.map((row) => (
                                <option key={row.key} value={row.key}>
                                    {row.advance_id === null ? t('adv.on_account') : row.number} · {formatMoney(row.open)}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <Field id="advance-refund-account" label={t('adv.account_out')} required>
                        <AccountSelect
                            id="advance-refund-account"
                            value={data.money_account_id}
                            accounts={accounts}
                            onChange={set('money_account_id')}
                        />
                    </Field>
                    <Field id="advance-refund-amount" label={t('adv.amount')} required>
                        <AmountBox id="advance-refund-amount" value={data.amount} onChange={set('amount')} />
                    </Field>
                    <Field id="advance-refund-reference" label={t('adv.reference')}>
                        <Input
                            id="advance-refund-reference"
                            className="w-56"
                            value={data.reference}
                            onChange={(e) => set('reference')(e.target.value)}
                        />
                    </Field>
                </>
            }
        />
    );
}
