import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * How this shop takes payment online: the SSLCommerz settings (slice P2a).
 *
 * ⛔ The store password never comes to this page. The page is told only whether
 * one is saved; leaving the field blank keeps it; removing it is a separate tick.
 *
 * ⚠ Sandbox is the default and live is said loudly: on live, a checkout charges a
 * real card or wallet.
 */

interface Gateway {
    is_enabled: boolean;
    merchant_id: string | null;
    has_secret: boolean;
    is_live: boolean;
    money_account_id: number | null;
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function StorePayments({
    store,
    gateway,
    problems,
    gateway_accounts,
    ipn_url,
    can_manage,
}: {
    store: { id: number; name: string; order_prefix: string };
    gateway: Gateway;
    problems: string[];
    gateway_accounts: { id: number; name: string; is_active: boolean }[];
    ipn_url: string | null;
    can_manage: boolean;
}) {
    const { data, setData, put, errors, processing, reset } = useForm({
        is_enabled: gateway.is_enabled,
        merchant_id: gateway.merchant_id ?? '',
        merchant_secret: '',
        clear_secret: false as boolean,
        is_live: gateway.is_live,
        money_account_id: gateway.money_account_id === null ? '' : String(gateway.money_account_id),
    });

    // Switching online payment on is refused without a store id, a password
    // (saved or typed) and a gateway account (StorePaymentGateway::problems()).
    const needsMerchant = data.is_enabled;
    const needsSecret = data.is_enabled && !gateway.has_secret;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/commerce/stores/${store.id}/payments`, { preserveScroll: true, onSuccess: () => reset('merchant_secret', 'clear_secret') });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Online shops', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
        { title: 'Payments', href: `/commerce/stores/${store.id}/payments` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${store.name} payments`} />

            <div className="grid max-w-3xl gap-6 p-4">
                <HeadingSmall
                    title={`${store.name} online payment`}
                    description="Cash on delivery is always offered. With SSLCommerz switched on, checkout also offers paying online by card or mobile wallet."
                />

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant={gateway.is_enabled ? 'default' : 'secondary'}>
                        {gateway.is_enabled ? 'Online payment on' : 'Online payment off'}
                    </Badge>
                    {gateway.is_enabled && (
                        <Badge variant={gateway.is_live ? 'destructive' : 'outline'}>{gateway.is_live ? 'LIVE - real money' : 'Sandbox'}</Badge>
                    )}
                </div>

                {!can_manage && <p className="text-muted-foreground text-sm">Only an Owner can change how this shop takes payment.</p>}

                {/* ⚠ Named before anybody presses anything, so switching on is never
                    the first time somebody learns what is missing. */}
                {problems.length > 0 && (
                    <div className="grid gap-1 rounded-lg border border-amber-500/40 p-3 text-sm">
                        <p className="font-medium text-amber-600 dark:text-amber-500">Checkout cannot take payment online until:</p>
                        <ul className="list-disc pl-5">
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
                    <div className="grid gap-1">
                        <Label htmlFor="merchant_id" required={needsMerchant}>
                            SSLCommerz store id
                        </Label>
                        <Input
                            id="merchant_id"
                            aria-required={needsMerchant}
                            className="w-72"
                            disabled={!can_manage}
                            value={data.merchant_id}
                            onChange={(e) => setData('merchant_id', e.target.value)}
                        />
                        <InputError message={errors.merchant_id} />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="merchant_secret" required={needsSecret}>
                            SSLCommerz store password
                        </Label>
                        <Input
                            id="merchant_secret"
                            aria-required={needsSecret}
                            type="password"
                            autoComplete="new-password"
                            className="w-72"
                            disabled={!can_manage || data.clear_secret}
                            placeholder={gateway.has_secret ? 'Saved - leave blank to keep it' : 'Not saved'}
                            value={data.merchant_secret}
                            onChange={(e) => setData('merchant_secret', e.target.value)}
                        />
                        <InputError message={errors.merchant_secret} />
                        {gateway.has_secret && can_manage && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.clear_secret} onChange={(e) => setData('clear_secret', e.target.checked)} />
                                Remove the saved password
                            </label>
                        )}
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="money_account_id" required={needsMerchant}>
                            Payments land in
                        </Label>
                        <select
                            id="money_account_id"
                            aria-required={needsMerchant}
                            className={`${selectClass} w-72`}
                            disabled={!can_manage}
                            value={data.money_account_id}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">Choose a payment gateway account</option>
                            {gateway_accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                    {account.is_active ? '' : ' (switched off)'}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.money_account_id} />
                        {gateway_accounts.length === 0 && (
                            <p className="text-muted-foreground text-xs">
                                None yet. Add one under{' '}
                                <Link href="/accounting/money-accounts" className="underline">
                                    Accounting &gt; Bank &amp; cash
                                </Link>{' '}
                                with the type Payment gateway.
                            </p>
                        )}
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" disabled={!can_manage} checked={data.is_live} onChange={(e) => setData('is_live', e.target.checked)} />
                        Live mode
                    </label>
                    {data.is_live && (
                        <p className="text-destructive text-sm font-medium">
                            Live: every online checkout charges a real card or wallet. Use the sandbox store id and password while testing.
                        </p>
                    )}

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            disabled={!can_manage}
                            checked={data.is_enabled}
                            onChange={(e) => setData('is_enabled', e.target.checked)}
                        />
                        Offer online payment at checkout
                    </label>
                    <InputError message={errors.is_enabled} />

                    {can_manage && (
                        <div>
                            <Button disabled={processing}>Save</Button>
                        </div>
                    )}
                </form>

                <div className="text-muted-foreground grid gap-1 text-sm">
                    <p>
                        Orders from this shop reach SSLCommerz as {store.order_prefix}-... references, which is how a shared merchant account tells
                        this shop's payments from another's.
                    </p>
                    {ipn_url ? (
                        <p>
                            In the SSLCommerz merchant panel, set the IPN listener to <span className="text-foreground font-mono">{ipn_url}</span>.
                        </p>
                    ) : (
                        <p>Give this shop a primary domain to see the IPN listener address for the SSLCommerz merchant panel.</p>
                    )}
                </div>

                <div>
                    <Button variant="outline" asChild>
                        <Link href={`/commerce/stores/${store.id}`}>Back to the shop</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
