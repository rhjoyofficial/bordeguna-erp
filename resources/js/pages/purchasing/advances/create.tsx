import { DateWords } from '@/components/form/date-words';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface Option {
    id: number;
    name: string;
}

interface PurchaseOrderRow {
    id: number;
    po_number: string;
    order_date: string | null;
}

interface MoneyAccountRow {
    id: number;
    name: string;
    type: string;
    balance?: string | null;
}

interface WithholdingRateRow {
    id: number;
    kind: 'tds' | 'vds';
    label: string;
    rate: number;
    supplier_type: string | null;
    from: string | null;
    to: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Payments', href: '/purchasing/payments' },
    { title: 'Advance', href: '/purchasing/advances/new' },
];

const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

/**
 * ⭐ Purchases > Payments, "Advance (no bill yet)" (ruling P2, 18 Oct 2026).
 *
 * Money paid to a supplier before the bill: Dr 1160 Advances to Suppliers /
 * Cr the account it left from. Optionally against a purchase order of the same
 * supplier, so the bill for that order proposes it first. TDS is held back
 * here when the supplier's rule applies at payment; the bill it later settles
 * is then never taxed again on the same amount.
 */
export default function AdvanceCreate({
    suppliers,
    selectedSupplierId,
    supplierType,
    purchaseOrders,
    selectedPurchaseOrderId,
    moneyAccounts,
    withholdingRates,
    today,
}: {
    suppliers: Option[];
    selectedSupplierId: number | null;
    supplierType: string | null;
    purchaseOrders: PurchaseOrderRow[];
    selectedPurchaseOrderId: number | null;
    moneyAccounts: MoneyAccountRow[];
    withholdingRates: WithholdingRateRow[];
    today: string;
}) {
    const { data, setData, post, errors, processing } = useForm({
        supplier_id: selectedSupplierId ? String(selectedSupplierId) : '',
        purchase_order_id: selectedPurchaseOrderId ? String(selectedPurchaseOrderId) : '',
        money_account_id: '',
        paid_on: today,
        amount: '',
        reference: '',
        note: '',
        tds_rate_id: '',
        vds_rate_id: '',
    });

    // The server decides which orders belong to the supplier.
    const chooseSupplier = (id: string) => {
        setData({ ...data, supplier_id: id, purchase_order_id: '' });
        router.get('/purchasing/advances/new', id ? { supplier_id: id } : {}, { preserveState: true, replace: true });
    };

    const ratesFor = (kind: 'tds' | 'vds') =>
        withholdingRates.filter(
            (r) =>
                r.kind === kind &&
                (r.supplier_type === null || r.supplier_type === supplierType) &&
                (r.from === null || r.from <= data.paid_on) &&
                (r.to === null || r.to >= data.paid_on),
        );
    const heldBy = (id: string) => {
        const rate = withholdingRates.find((r) => String(r.id) === id);
        return rate ? Math.round((toPaisa(data.amount) * rate.rate) / 100) : 0;
    };
    const held = heldBy(data.tds_rate_id) + heldBy(data.vds_rate_id);
    const gross = toPaisa(data.amount);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/purchasing/advances', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pay an advance" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap gap-2 text-sm" role="tablist" aria-label="Kind of payment">
                    <Link
                        role="tab"
                        aria-selected="false"
                        href={`/purchasing/payments/new${data.supplier_id ? `?supplier_id=${data.supplier_id}` : ''}`}
                        className="hover:bg-muted rounded-full border px-3 py-1"
                    >
                        Against bills
                    </Link>
                    <span role="tab" aria-selected="true" className="bg-primary text-primary-foreground rounded-full px-3 py-1">
                        Advance (no bill yet)
                    </span>
                </div>

                <p className="text-muted-foreground max-w-2xl text-sm">
                    Money paid before the supplier&apos;s bill. It is held as an advance (what the supplier owes us in goods) until a bill uses it or
                    the supplier refunds it.
                </p>

                <div className="flex flex-wrap items-start gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id" required>
                            Supplier
                        </Label>
                        <SearchSelect fit id="supplier_id" value={data.supplier_id} onChange={(e) => chooseSupplier(e.target.value)}>
                            <option value="">Select a supplier</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.supplier_id && <p className="text-destructive text-sm">{errors.supplier_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="purchase_order_id">Purchase order (optional)</Label>
                        <SearchSelect
                            fit
                            id="purchase_order_id"
                            value={data.purchase_order_id}
                            onChange={(e) => setData('purchase_order_id', e.target.value)}
                            disabled={data.supplier_id === ''}
                        >
                            <option value="">No purchase order</option>
                            {purchaseOrders.map((po) => (
                                <option key={po.id} value={po.id} data-hint={po.order_date ? showDate(po.order_date) : undefined}>
                                    {po.po_number}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.purchase_order_id && <p className="text-destructive text-sm">{errors.purchase_order_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id" required>
                            Paid from
                        </Label>
                        <SearchSelect
                            fit
                            id="money_account_id"
                            value={data.money_account_id}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">Select an account</option>
                            {moneyAccounts.map((account) => (
                                <option
                                    key={account.id}
                                    value={account.id}
                                    data-hint={account.balance != null ? `Balance now ${formatMoney(account.balance)}` : undefined}
                                >
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-sm">{errors.money_account_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="paid_on" required>
                            Payment date
                        </Label>
                        <Input
                            id="paid_on"
                            type="date"
                            required
                            className="w-auto"
                            value={data.paid_on}
                            onChange={(e) => setData('paid_on', e.target.value)}
                        />
                        <DateWords value={data.paid_on} />
                        {errors.paid_on && <p className="text-destructive text-sm">{errors.paid_on}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="amount" required>
                            Advance amount
                        </Label>
                        <Input
                            id="amount"
                            type="number"
                            step="0.01"
                            min="0"
                            className="w-40 text-right tabular-nums"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                        />
                        {errors.amount && <p className="text-destructive text-sm">{errors.amount}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            className="w-72"
                            value={data.reference}
                            placeholder="Cheque number, bKash trx id, slip number"
                            onChange={(e) => setData('reference', e.target.value)}
                        />
                        {errors.reference && <p className="text-destructive text-sm">{errors.reference}</p>}
                    </div>
                </div>

                {data.supplier_id !== '' && (ratesFor('tds').length > 0 || ratesFor('vds').length > 0) && (
                    <div className="flex flex-wrap items-start gap-4">
                        {(['tds', 'vds'] as const).map((kind) =>
                            ratesFor(kind).length === 0 ? null : (
                                <div key={kind} className="grid gap-2">
                                    <Label htmlFor={`${kind}_rate_id`}>{kind === 'tds' ? 'Hold back TDS' : 'Hold back VDS'}</Label>
                                    <SearchSelect
                                        fit
                                        id={`${kind}_rate_id`}
                                        value={data[`${kind}_rate_id`]}
                                        onChange={(e) => setData(`${kind}_rate_id`, e.target.value)}
                                    >
                                        <option value="">None</option>
                                        {ratesFor(kind).map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    {errors[`${kind}_rate_id`] && <p className="text-destructive text-sm">{errors[`${kind}_rate_id`]}</p>}
                                </div>
                            ),
                        )}
                        {held > 0 && (
                            <p className="text-muted-foreground self-end pb-2 text-sm">
                                Tax held back {formatMoney(held / 100)}. Leaves the account:{' '}
                                <span className="font-medium">{formatMoney((gross - held) / 100)}</span>
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="note">Note</Label>
                    <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>

                <div>
                    <Button type="submit" disabled={processing || gross <= 0}>
                        Record advance of {formatMoney(gross / 100)}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
