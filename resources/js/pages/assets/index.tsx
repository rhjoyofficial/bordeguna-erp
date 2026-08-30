import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Asset {
    id: number;
    asset_code: string;
    name: string;
    /** ⚠ A plain Y-m-d from the server; the raw date cast serialises in UTC. */
    acquired_on: string | null;
    account: string | null;
    paid_from: string | null;
    supplier: string | null;
    cost: string;
    salvage_value: string;
    useful_life_months: number;
    depreciation_starts_on: string | null;
    accumulated: string;
    book_value: string;
    monthly_charge: string;
    is_fully_depreciated: boolean;
    note: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Fixed assets', href: '/assets' }];

export default function AssetsIndex({
    assets,
    canManage,
    assetAccounts,
    moneyAccounts,
    suppliers,
    today,
}: {
    assets: Paginated<Asset>;
    canManage: boolean;
    assetAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    suppliers: { id: number; name: string }[];
    today: string;
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        asset_code: '',
        name: '',
        account_id: '',
        acquired_on: today,
        cost: '',
        salvage_value: '',
        useful_life_months: '',
        depreciation_starts_on: '',
        money_account_id: '',
        supplier_id: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('assets.store'), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fixed assets" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Fixed assets</h1>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        Things the business owns and uses rather than sells - a van, a chiller, a computer. Recorded here they stay on the balance
                        sheet and wear down month by month. Bought through Purchasing instead, they become stock and are written off as cost of sales
                        the first time they are issued.
                    </p>
                </div>

                {canManage && (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="asset_code">Code</Label>
                            <Input
                                id="asset_code"
                                value={data.asset_code}
                                onChange={(e) => setData('asset_code', e.target.value)}
                                className="w-32"
                                placeholder="VAN-01"
                            />
                            <InputError message={errors.asset_code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">What it is</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-56"
                                placeholder="Delivery van"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="account_id">Reports under</Label>
                            {/*
                              ⚠⚠ Only NON-CURRENT asset accounts, and never Accumulated
                              Depreciation. Every postable asset account would include
                              Cash at Bank and Stock on Hand - a list whose choices are
                              overwhelmingly wrong.
                            */}
                            <SearchSelect fit id="account_id" value={data.account_id} onChange={(e) => setData('account_id', e.target.value)}>
                                <option value="">Choose an asset account</option>
                                {assetAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="acquired_on">Acquired</Label>
                            <Input id="acquired_on" type="date" value={data.acquired_on} onChange={(e) => setData('acquired_on', e.target.value)} />
                            <InputError message={errors.acquired_on} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="cost">Cost</Label>
                            <Input
                                id="cost"
                                value={data.cost}
                                onChange={(e) => setData('cost', e.target.value)}
                                className="w-32 text-right"
                                placeholder="0.00"
                                inputMode="decimal"
                            />
                            <InputError message={errors.cost} />
                        </div>

                        <div className="grid gap-2">
                            {/* What it is expected to be worth at the end of its life;
                                never written off, so it stays on the balance sheet. */}
                            <Label htmlFor="salvage_value">Salvage value</Label>
                            <Input
                                id="salvage_value"
                                value={data.salvage_value}
                                onChange={(e) => setData('salvage_value', e.target.value)}
                                className="w-32 text-right"
                                placeholder="0.00"
                                inputMode="decimal"
                            />
                            <InputError message={errors.salvage_value} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="useful_life_months">Useful life (months)</Label>
                            <Input
                                id="useful_life_months"
                                value={data.useful_life_months}
                                onChange={(e) => setData('useful_life_months', e.target.value)}
                                className="w-24 text-right"
                                placeholder="36"
                                inputMode="numeric"
                            />
                            <InputError message={errors.useful_life_months} />
                        </div>

                        <div className="grid gap-2">
                            {/*
                              ⚠ Optional. Leaving it empty records the asset in the
                              register WITHOUT any journal - knowing what you own is
                              not an accounting question, and the engine skips the
                              posting deliberately in that case.
                            */}
                            <Label htmlFor="money_account_id">Paid from (optional)</Label>
                            <SearchSelect
                                fit
                                id="money_account_id"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Not paid through the ERP</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="supplier_id">Bought from (optional)</Label>
                            <SearchSelect fit id="supplier_id" value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                                <option value="">Not recorded</option>
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.supplier_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="depreciation_starts_on">Depreciation starts (optional)</Label>
                            <Input
                                id="depreciation_starts_on"
                                type="date"
                                value={data.depreciation_starts_on}
                                onChange={(e) => setData('depreciation_starts_on', e.target.value)}
                            />
                            <InputError message={errors.depreciation_starts_on} />
                        </div>

                        <Button disabled={processing}>Record asset</Button>
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[64rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Code</th>
                                <th className="p-3">Asset</th>
                                <th className="p-3">Acquired</th>
                                <th className="p-3">Reports under</th>
                                <th className="p-3 text-right">Cost</th>
                                <th className="p-3 text-right">Written off</th>
                                <th className="p-3 text-right">Book value</th>
                                <th className="p-3 text-right">Per month</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assets.data.map((asset) => (
                                <tr key={asset.id} className="border-t">
                                    <td className="p-3 font-medium">{asset.asset_code}</td>
                                    <td className="p-3">
                                        {asset.name}
                                        {asset.is_fully_depreciated && (
                                            <Badge variant="secondary" className="ml-2">
                                                Fully written off
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{asset.acquired_on ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">{asset.account ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{asset.cost}</td>
                                    <td className="p-3 text-right tabular-nums">{asset.accumulated}</td>
                                    <td className="p-3 text-right font-medium tabular-nums">{asset.book_value}</td>
                                    <td className="text-muted-foreground p-3 text-right tabular-nums">{asset.monthly_charge}</td>
                                </tr>
                            ))}
                            {assets.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                        Nothing recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={assets} />

                <p className="text-muted-foreground text-sm">
                    Assets wear down only when somebody runs it:{' '}
                    <Link href="/assets/depreciation" className="underline">
                        depreciation
                    </Link>
                    .
                </p>
            </div>
        </AppLayout>
    );
}
