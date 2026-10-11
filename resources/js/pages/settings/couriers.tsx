import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useState } from 'react';

type CredentialField = { key: string; label: string; secret: boolean; required: boolean; hint?: string };
type OptionField = {
    key: string;
    label: string;
    type: 'select' | 'number';
    default: number | string;
    choices?: Record<string, string>;
    min?: number;
    max?: number;
    step?: number;
    hint?: string;
};

type Provider = {
    code: string;
    name: string;
    connected: boolean;
    credential_fields: CredentialField[];
    option_fields: OptionField[];
    base_urls: Record<string, Record<string, string>>;
    capabilities: Record<string, boolean | null>;
};

type Store = { id: string; name: string; active: boolean };

type Account = {
    id: number;
    provider: string;
    provider_name: string;
    label: string;
    environment: 'sandbox' | 'live';
    base_url: string;
    options: Record<string, number | string>;
    /** Which credential fields hold a value. Never the value itself. */
    credentials_set: Record<string, boolean>;
    is_active: boolean;
    activated_at: string | null;
    last_test_at: string | null;
    last_test_ok: boolean | null;
    last_test_message: string | null;
    last_test_city_count: number | null;
    test_is_current: boolean;
    can_activate: boolean;
    stores: Store[];
    webhook_url: string;
    // Courier hub slice 4: Pathao's echo value, set or not (never the value),
    // and the automatic money switch, stored off.
    integration_secret_set: boolean;
    auto_post_outcomes: boolean;
    created_by: string | null;
    other_business_count: number;
    // ⭐ Joy, 11 Oct 2026: one link per brand the account carries here (brand null
    // = any brand), each with its own store, money account and default.
    links: BusinessLinkRow[];
};

type BusinessLinkRow = {
    id: number;
    brand_id: number | null;
    brand_name: string | null;
    provider_store_id: string | null;
    provider_store_name: string | null;
    money_account_id: number | null;
    is_default: boolean;
};

type BrandOption = { id: number; code: string; name: string; is_active: boolean };

type PageProps = {
    providers: Provider[];
    accounts: Account[];
    moneyAccounts: { id: number; label: string }[];
    brands: BrandOption[];
    revealedSecret: { account_id: number; secret: string } | null;
    errors: Record<string, string | undefined>;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Couriers', href: '/settings/couriers' }];

const CAPABILITY_LABELS: Record<string, string> = {
    test_connection: 'Connection test',
    stores: 'Pickup stores',
    locations: 'City and area lists',
    book: 'Booking',
    bulk_book: 'Bulk booking',
    quote: 'Price quote',
    cancel: 'Cancel',
    webhook: 'Status webhook',
    status_lookup: 'Status lookup',
};

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

type AccountFormData = {
    provider: string;
    label: string;
    environment: string;
    base_url: string;
    credentials: Record<string, string>;
    options: Record<string, string>;
    integration_secret: string;
};

function initialData(provider: Provider, account?: Account): AccountFormData {
    const environment = account?.environment ?? 'live';
    const urls = Object.keys(provider.base_urls[environment] ?? {});

    return {
        provider: provider.code,
        label: account?.label ?? '',
        environment,
        base_url: account?.base_url ?? urls[0] ?? '',
        // ⚠ Always blank: a saved credential never comes back to the browser,
        // and blank on save means "keep what is saved".
        credentials: Object.fromEntries(provider.credential_fields.map((f) => [f.key, ''])),
        options: Object.fromEntries(provider.option_fields.map((f) => [f.key, String(account?.options?.[f.key] ?? f.default)])),
        // ⚠ Always blank, like a credential: blank on save keeps what is saved.
        integration_secret: '',
    };
}

/**
 * The form for one account, drawn from the provider's own description: its
 * credential fields, its options, and the official URLs for each environment.
 */
function AccountForm({ providers, account, onDone }: { providers: Provider[]; account?: Account; onDone: () => void }) {
    const connected = providers.filter((p) => p.connected);
    const startProvider = account ? providers.find((p) => p.code === account.provider)! : connected[0];
    const form = useForm<AccountFormData>(initialData(startProvider, account));
    const [replacing, setReplacing] = useState<Record<string, boolean>>({});

    const provider = providers.find((p) => p.code === form.data.provider) ?? startProvider;
    const urls = provider.base_urls[form.data.environment] ?? {};
    const errors = form.errors as Record<string, string | undefined>;

    const changeProvider = (code: string) => {
        const next = providers.find((p) => p.code === code);
        if (next) form.setData({ ...initialData(next), label: form.data.label });
    };

    const changeEnvironment = (environment: string) => {
        const first = Object.keys(provider.base_urls[environment] ?? {})[0] ?? '';
        form.setData({ ...form.data, environment, base_url: first });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };
        if (account) {
            form.put(route('couriers.settings.update', account.id), options);
        } else {
            form.post(route('couriers.settings.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
            <FormRow>
                <Field label="Courier" htmlFor={`provider-${account?.id ?? 'new'}`} size="md" error={errors.provider} required>
                    <select
                        id={`provider-${account?.id ?? 'new'}`}
                        className={selectClass}
                        value={form.data.provider}
                        disabled={account !== undefined}
                        onChange={(e) => changeProvider(e.target.value)}
                    >
                        {connected.map((p) => (
                            <option key={p.code} value={p.code}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    label="Label"
                    htmlFor={`label-${account?.id ?? 'new'}`}
                    size="md"
                    error={errors.label}
                    required
                    hint="For example LaLaDia or Bionic."
                >
                    <Input
                        id={`label-${account?.id ?? 'new'}`}
                        value={form.data.label}
                        maxLength={120}
                        onChange={(e) => form.setData('label', e.target.value)}
                    />
                </Field>
                <Field label="Environment" htmlFor={`env-${account?.id ?? 'new'}`} size="sm" error={errors.environment} required>
                    <select
                        id={`env-${account?.id ?? 'new'}`}
                        className={selectClass}
                        value={form.data.environment}
                        onChange={(e) => changeEnvironment(e.target.value)}
                    >
                        <option value="live">Live</option>
                        <option value="sandbox">Sandbox (test)</option>
                    </select>
                </Field>
                <Field
                    label="Address"
                    htmlFor={`url-${account?.id ?? 'new'}`}
                    size="grow"
                    error={errors.base_url}
                    required
                    hint="Only the courier's official addresses are offered, so the credentials can never be sent anywhere else."
                >
                    <select
                        id={`url-${account?.id ?? 'new'}`}
                        className={selectClass}
                        value={form.data.base_url}
                        onChange={(e) => form.setData('base_url', e.target.value)}
                    >
                        {Object.entries(urls).map(([url, label]) => (
                            <option key={url} value={url}>
                                {label}
                            </option>
                        ))}
                    </select>
                </Field>
            </FormRow>

            <div className="space-y-2">
                <p className="text-sm font-medium">Credentials</p>
                <FormRow>
                    {provider.credential_fields.map((f) => {
                        const saved = account?.credentials_set[f.key] ?? false;
                        const id = `cred-${account?.id ?? 'new'}-${f.key}`;

                        return (
                            <Field
                                key={f.key}
                                label={f.label}
                                htmlFor={id}
                                size="lg"
                                error={errors[`credentials.${f.key}`]}
                                required={f.required && !saved}
                                hint={f.hint}
                            >
                                {saved && !replacing[f.key] ? (
                                    <div className="flex h-9 items-center gap-2">
                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                            Set
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setReplacing({ ...replacing, [f.key]: true })}
                                        >
                                            Replace
                                        </Button>
                                    </div>
                                ) : (
                                    <Input
                                        id={id}
                                        type={f.secret ? 'password' : 'text'}
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder={saved ? 'Type the new value, or leave blank to keep the saved one' : ''}
                                        value={form.data.credentials[f.key] ?? ''}
                                        onChange={(e) => form.setData('credentials', { ...form.data.credentials, [f.key]: e.target.value })}
                                    />
                                )}
                            </Field>
                        );
                    })}
                </FormRow>
            </div>

            {provider.option_fields.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Options</p>
                    <FormRow>
                        {provider.option_fields.map((f) => {
                            const id = `opt-${account?.id ?? 'new'}-${f.key}`;

                            return (
                                <Field key={f.key} label={f.label} htmlFor={id} size="md" error={errors[`options.${f.key}`]} hint={f.hint}>
                                    {f.type === 'select' ? (
                                        <select
                                            id={id}
                                            className={selectClass}
                                            value={form.data.options[f.key]}
                                            onChange={(e) => form.setData('options', { ...form.data.options, [f.key]: e.target.value })}
                                        >
                                            {Object.entries(f.choices ?? {}).map(([value, label]) => (
                                                <option key={value} value={value}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={f.min}
                                            max={f.max}
                                            step={f.step ?? 'any'}
                                            value={form.data.options[f.key]}
                                            onChange={(e) => form.setData('options', { ...form.data.options, [f.key]: e.target.value })}
                                        />
                                    )}
                                </Field>
                            );
                        })}
                    </FormRow>
                </div>
            )}

            {provider.code === 'pathao' && (
                <FormRow>
                    <Field
                        label="Webhook integration secret"
                        htmlFor={`echo-${account?.id ?? 'new'}`}
                        size="lg"
                        error={errors.integration_secret}
                        hint={
                            account?.integration_secret_set
                                ? 'Saved. Leave blank to keep it. Pathao shows this value on its webhook page; the ERP sends it back on every answer.'
                                : 'Pathao shows this value on its webhook page; the ERP sends it back on every answer.'
                        }
                    >
                        <Input
                            id={`echo-${account?.id ?? 'new'}`}
                            type="password"
                            autoComplete="off"
                            value={form.data.integration_secret}
                            onChange={(e) => form.setData('integration_secret', e.target.value)}
                        />
                    </Field>
                </FormRow>
            )}

            {account?.is_active && (
                <p className="text-muted-foreground text-xs">
                    Changing a credential, the environment or the address switches this account off until it passes a new test.
                </p>
            )}

            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    {account ? 'Save changes' : 'Save account'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * Which pickup store and which courier money account THIS business uses with
 * the account, and for which brand's orders (Joy, 11 Oct 2026). The store list
 * is what the courier returned in the last test.
 */
function BusinessLink({ account, moneyAccounts, brands }: { account: Account; moneyAccounts: PageProps['moneyAccounts']; brands: BrandOption[] }) {
    const linkFor = (brand: string) => account.links.find((l) => (l.brand_id === null ? '' : String(l.brand_id)) === brand);
    const valuesFor = (brand: string) => {
        const link = linkFor(brand);

        return {
            brand_id: brand,
            provider_store_id: link?.provider_store_id ?? account.stores.find((s) => s.active)?.id ?? '',
            money_account_id: link?.money_account_id ? String(link.money_account_id) : String(moneyAccounts[0]?.id ?? ''),
            is_default: link?.is_default ?? false,
        };
    };
    const firstBrand = account.links[0] ? (account.links[0].brand_id === null ? '' : String(account.links[0].brand_id)) : '';
    const form = useForm(valuesFor(firstBrand));
    const errors = form.errors as Record<string, string | undefined>;
    const editing = linkFor(form.data.brand_id);
    const brandName = (link: BusinessLinkRow) => (link.brand_id === null ? 'Any brand' : (link.brand_name ?? 'A brand'));

    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">This business</p>
            {account.links.length > 0 ? (
                <ul className="text-muted-foreground space-y-1 text-sm">
                    {account.links.map((link) => (
                        <li key={link.id}>
                            <strong className="text-foreground">{brandName(link)}</strong>: ships from{' '}
                            <strong>{link.provider_store_name ?? link.provider_store_id}</strong>
                            {link.is_default ? ', the default for this courier' : ''}.
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground text-sm">Not used by this business yet.</p>
            )}
            {account.other_business_count > 0 && (
                <p className="text-muted-foreground text-xs">
                    Also used by {account.other_business_count} other business(es), each with its own store and money account.
                </p>
            )}

            {account.stores.length === 0 ? (
                <p className="text-muted-foreground text-sm">Run Test connection first: the pickup stores come from the courier.</p>
            ) : moneyAccounts.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    This business has no courier money account yet. Add one in Accounting, Bank and cash, with the type Courier, then come back.
                </p>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({ ...data, brand_id: data.brand_id === '' ? null : Number(data.brand_id) }));
                        form.put(route('couriers.settings.link', account.id), { preserveScroll: true });
                    }}
                    className="space-y-3"
                >
                    <FormRow>
                        {brands.length > 0 && (
                            <Field label="For orders of" htmlFor={`brand-${account.id}`} size="md" error={errors.brand_id}>
                                <select
                                    id={`brand-${account.id}`}
                                    className={selectClass}
                                    value={form.data.brand_id}
                                    onChange={(e) => form.setData(valuesFor(e.target.value))}
                                >
                                    <option value="">Any brand</option>
                                    {brands.map((b) => (
                                        <option key={b.id} value={String(b.id)}>
                                            {b.name}
                                            {b.is_active ? '' : ' (not in use)'}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                        <Field label="Pickup store" htmlFor={`store-${account.id}`} size="lg" error={errors.provider_store_id} required>
                            <select
                                id={`store-${account.id}`}
                                className={selectClass}
                                value={form.data.provider_store_id}
                                onChange={(e) => form.setData('provider_store_id', e.target.value)}
                            >
                                <option value="">Choose a store</option>
                                {account.stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.id}){s.active ? '' : ', inactive at the courier'}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Courier money account" htmlFor={`money-${account.id}`} size="lg" error={errors.money_account_id} required>
                            <select
                                id={`money-${account.id}`}
                                className={selectClass}
                                value={form.data.money_account_id}
                                onChange={(e) => form.setData('money_account_id', e.target.value)}
                            >
                                {moneyAccounts.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </FormRow>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked)} />
                        Default account for this courier in this business
                        {brands.length > 0 ? (form.data.brand_id === '' ? ', for any brand' : ', for this brand') : ''}
                    </label>
                    {brands.length > 0 && (
                        <p className="text-muted-foreground text-xs">
                            A parcel goes through the account linked to its order's brand, else one linked for any brand. An account linked only to
                            another brand is never offered for it.
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={form.processing}>
                            {editing ? 'Save' : 'Use for this business'}
                        </Button>
                        {editing && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        route('couriers.settings.unlink', {
                                            courierAccount: account.id,
                                            brand: editing.brand_id === null ? 'any' : String(editing.brand_id),
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Stop using here{brands.length > 0 ? ` for ${editing.brand_id === null ? 'any brand' : brandName(editing)}` : ''}
                            </Button>
                        )}
                    </div>
                </form>
            )}
        </div>
    );
}

export default function CourierSettings() {
    const { providers, accounts, moneyAccounts, brands = [], revealedSecret, errors } = usePage<PageProps>().props;
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [busyId, setBusyId] = useState<number | null>(null);

    const post = (name: string, id: number) => {
        setBusyId(id);
        router.post(route(name, id), {}, { preserveScroll: true, onFinish: () => setBusyId(null) });
    };

    const notConnected = providers.filter((p) => !p.connected);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Couriers" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall
                            title="Couriers"
                            description="Courier accounts, their credentials, the pickup store each business uses and where the cash on delivery is owed."
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href={route('couriers.settings.locations.index')}>Locations and bands</Link>
                            </Button>
                            {!adding && <Button onClick={() => setAdding(true)}>Add a courier account</Button>}
                        </div>
                    </div>

                    {errors.activate && <InputError message={errors.activate} />}

                    {adding && <AccountForm providers={providers} onDone={() => setAdding(false)} />}

                    {accounts.length === 0 && !adding && (
                        <div className="border-border flex flex-col items-start gap-2 rounded-md border border-dashed p-6">
                            <Truck className="text-muted-foreground size-6" />
                            <p className="font-medium">No courier is connected yet</p>
                            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                                <li>Add a courier account and type the credentials from the courier's merchant website.</li>
                                <li>Press Test connection. It only reads: the pickup stores and the city list.</li>
                                <li>When the test passes, choose this business's pickup store and courier money account.</li>
                                <li>Activate the account.</li>
                            </ol>
                        </div>
                    )}

                    <div className="space-y-4">
                        {accounts.map((a) => (
                            <div key={a.id} className="border-border space-y-4 rounded-md border p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 space-y-1">
                                        <p className="font-medium">
                                            {a.provider_name}: {a.label}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2 text-xs">
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5',
                                                    a.environment === 'live'
                                                        ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'
                                                        : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                                )}
                                            >
                                                {a.environment === 'live' ? 'Live' : 'Sandbox'}
                                            </span>
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5',
                                                    a.is_active
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {a.is_active ? 'Active' : 'Off'}
                                            </span>
                                            <span className="text-muted-foreground">{a.base_url}</span>
                                        </div>
                                        {a.last_test_at === null ? (
                                            <p className="text-muted-foreground text-xs">Not tested yet.</p>
                                        ) : a.last_test_ok ? (
                                            <p
                                                className={cn(
                                                    'text-xs',
                                                    a.test_is_current
                                                        ? 'text-emerald-700 dark:text-emerald-300'
                                                        : 'text-amber-700 dark:text-amber-300',
                                                )}
                                            >
                                                {a.test_is_current
                                                    ? `Passed on ${a.last_test_at}. ${a.last_test_message ?? ''}`
                                                    : `Passed on ${a.last_test_at}, but the credentials or address changed since. Test again.`}
                                            </p>
                                        ) : (
                                            <p className="text-destructive text-xs">
                                                Failed on {a.last_test_at}: {a.last_test_message}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busyId === a.id}
                                            onClick={() => post('couriers.settings.test', a.id)}
                                        >
                                            {busyId === a.id ? 'Testing...' : 'Test connection'}
                                        </Button>
                                        {a.is_active ? (
                                            <Button variant="outline" size="sm" onClick={() => post('couriers.settings.deactivate', a.id)}>
                                                Deactivate
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                disabled={!a.can_activate}
                                                title={a.can_activate ? undefined : 'Needs a passing test with the current credentials'}
                                                onClick={() => post('couriers.settings.activate', a.id)}
                                            >
                                                Activate
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="sm" onClick={() => setEditingId(editingId === a.id ? null : a.id)}>
                                            {editingId === a.id ? 'Close' : 'Edit'}
                                        </Button>
                                    </div>
                                </div>

                                {editingId === a.id && <AccountForm providers={providers} account={a} onDone={() => setEditingId(null)} />}

                                {a.test_is_current && a.stores.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium">
                                            Pickup stores the courier returned
                                            {a.last_test_city_count !== null ? `, ${a.last_test_city_count} cities` : ''}
                                        </p>
                                        <ul className="text-muted-foreground text-sm">
                                            {a.stores.map((s) => (
                                                <li key={s.id}>
                                                    {s.name} ({s.id}){s.active ? '' : ', inactive'}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <div className="grid gap-6 md:grid-cols-2">
                                    <BusinessLink account={a} moneyAccounts={moneyAccounts} brands={brands} />

                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">Webhook</p>
                                        <p className="text-muted-foreground text-xs">
                                            The address to give the courier for status updates, with the webhook secret. A status update never posts
                                            money: a person confirms each parcel on its order.
                                        </p>
                                        <code className="bg-muted block rounded px-2 py-1 text-xs break-all">{a.webhook_url}</code>
                                        {revealedSecret?.account_id === a.id && (
                                            <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs dark:border-amber-800 dark:bg-amber-950/40">
                                                <p className="font-medium">New secret, shown once. Copy it now:</p>
                                                <code className="break-all">{revealedSecret.secret}</code>
                                            </div>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                if (window.confirm('Make a new webhook secret? The old one stops working.'))
                                                    post('couriers.settings.rotate-secret', a.id);
                                            }}
                                        >
                                            Rotate webhook secret
                                        </Button>
                                    </div>
                                </div>

                                <div className="space-y-1">
                                    <p className="text-sm font-medium">Post delivered parcels automatically</p>
                                    <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <input type="checkbox" checked={a.auto_post_outcomes} disabled readOnly />
                                        {a.auto_post_outcomes ? 'On' : 'Off'}: available after the evidence period. Until then a person confirms every
                                        parcel.
                                    </label>
                                    {a.auto_post_outcomes && (
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => router.post(route('couriers.settings.auto-post', a.id), { on: false })}
                                        >
                                            Emergency off
                                        </Button>
                                    )}
                                </div>

                                <p className="text-muted-foreground text-xs">{a.created_by ? `Added by ${a.created_by}.` : ''}</p>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-3">
                        <h3 className="text-sm font-medium">Couriers the ERP knows</h3>
                        <div className="grid gap-3 md:grid-cols-2">
                            {providers.map((p) => (
                                <div key={p.code} className="border-border rounded-md border p-3 text-sm">
                                    <p className="font-medium">
                                        {p.name}{' '}
                                        <span className="text-muted-foreground text-xs font-normal">
                                            {p.connected ? 'Connected' : 'Not yet connected'}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {p.connected
                                            ? Object.entries(p.capabilities)
                                                  .map(
                                                      ([k, v]) =>
                                                          `${CAPABILITY_LABELS[k] ?? k}: ${v === true ? 'yes' : v === false ? 'no' : 'unknown'}`,
                                                  )
                                                  .join(' · ')
                                            : 'Its API has not been checked yet, so no account can be added for it.'}
                                    </p>
                                </div>
                            ))}
                        </div>
                        {notConnected.length > 0 && (
                            <p className="text-muted-foreground text-xs">
                                {notConnected.map((p) => p.name).join(', ')} can be connected once their APIs are checked.
                            </p>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
