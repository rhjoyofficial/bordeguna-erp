import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Truck } from 'lucide-react';
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
    /** The pairing guard: the header the courier wants its own value back in, where it shows that value, and the hint. */
    integration_echo_header: string | null;
    integration_echo_page: string | null;
    integration_echo_hint_key: string | null;
};

type WebhookStatus = {
    /** The courier wants a reply value and none is saved: its connect button will say "Validation error". */
    needs_echo: boolean;
    last_handshake_at: string | null;
    last_handshake_ok: boolean | null;
    problem: 'missing_echo' | 'bad_signature' | null;
    last_event_at: string | null;
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
    webhook_status: WebhookStatus;
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
    const { t, tr } = useTranslation();
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
                <Field label={t('couriers.courier')} htmlFor={`provider-${account?.id ?? 'new'}`} size="md" error={errors.provider} required>
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
                    label={t('couriers.label')}
                    htmlFor={`label-${account?.id ?? 'new'}`}
                    size="md"
                    error={errors.label}
                    required
                    hint={t('couriers.label_hint')}
                >
                    <Input
                        id={`label-${account?.id ?? 'new'}`}
                        value={form.data.label}
                        maxLength={120}
                        onChange={(e) => form.setData('label', e.target.value)}
                    />
                </Field>
                <Field label={t('couriers.environment')} htmlFor={`env-${account?.id ?? 'new'}`} size="sm" error={errors.environment} required>
                    <select
                        id={`env-${account?.id ?? 'new'}`}
                        className={selectClass}
                        value={form.data.environment}
                        onChange={(e) => changeEnvironment(e.target.value)}
                    >
                        <option value="live">{t('couriers.live')}</option>
                        <option value="sandbox">{t('couriers.sandbox_test')}</option>
                    </select>
                </Field>
                <Field
                    label={t('couriers.address')}
                    htmlFor={`url-${account?.id ?? 'new'}`}
                    size="grow"
                    error={errors.base_url}
                    required
                    hint={t('couriers.address_hint')}
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
                <p className="text-sm font-medium">{t('couriers.credentials')}</p>
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
                                            {t('couriers.set')}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setReplacing({ ...replacing, [f.key]: true })}
                                        >
                                            {t('couriers.replace')}
                                        </Button>
                                    </div>
                                ) : (
                                    <Input
                                        id={id}
                                        type={f.secret ? 'password' : 'text'}
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder={saved ? t('couriers.replace_placeholder') : ''}
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
                    <p className="text-sm font-medium">{t('couriers.options')}</p>
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

            {provider.integration_echo_header !== null && (
                <FormRow>
                    <Field
                        label={t('couriers.integration_secret')}
                        htmlFor={`echo-${account?.id ?? 'new'}`}
                        size="grow"
                        error={errors.integration_secret}
                        hint={[
                            account?.integration_secret_set ? t('couriers.integration_secret_saved') : '',
                            provider.integration_echo_hint_key
                                ? tr(provider.integration_echo_hint_key, '', { header: provider.integration_echo_header })
                                : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
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

            {account?.is_active && <p className="text-muted-foreground text-xs">{t('couriers.change_switches_off')}</p>}

            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    {account ? t('couriers.save_changes') : t('couriers.save_account')}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('couriers.cancel')}
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
    const { t } = useTranslation();
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
    const brandName = (link: BusinessLinkRow) => (link.brand_id === null ? t('couriers.any_brand') : (link.brand_name ?? t('couriers.a_brand')));

    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">{t('couriers.this_business')}</p>
            {account.links.length > 0 ? (
                <ul className="text-muted-foreground space-y-1 text-sm">
                    {account.links.map((link) => (
                        <li key={link.id}>
                            <strong className="text-foreground">{brandName(link)}</strong>: {t('couriers.ships_from')}{' '}
                            <strong>{link.provider_store_name ?? link.provider_store_id}</strong>
                            {link.is_default ? t('couriers.default_for_courier') : ''}.
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground text-sm">{t('couriers.not_used_yet')}</p>
            )}
            {account.other_business_count > 0 && (
                <p className="text-muted-foreground text-xs">{t('couriers.other_businesses', { count: account.other_business_count })}</p>
            )}

            {account.stores.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('couriers.test_first')}</p>
            ) : moneyAccounts.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('couriers.no_money_account')}</p>
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
                            <Field label={t('couriers.for_orders_of')} htmlFor={`brand-${account.id}`} size="md" error={errors.brand_id}>
                                <select
                                    id={`brand-${account.id}`}
                                    className={selectClass}
                                    value={form.data.brand_id}
                                    onChange={(e) => form.setData(valuesFor(e.target.value))}
                                >
                                    <option value="">{t('couriers.any_brand')}</option>
                                    {brands.map((b) => (
                                        <option key={b.id} value={String(b.id)}>
                                            {b.name}
                                            {b.is_active ? '' : t('couriers.brand_not_in_use')}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                        <Field label={t('couriers.pickup_store')} htmlFor={`store-${account.id}`} size="lg" error={errors.provider_store_id} required>
                            <select
                                id={`store-${account.id}`}
                                className={selectClass}
                                value={form.data.provider_store_id}
                                onChange={(e) => form.setData('provider_store_id', e.target.value)}
                            >
                                <option value="">{t('couriers.choose_store')}</option>
                                {account.stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.id}){s.active ? '' : t('couriers.inactive_at_courier')}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('couriers.money_account')} htmlFor={`money-${account.id}`} size="lg" error={errors.money_account_id} required>
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
                        {t('couriers.default_checkbox')}
                        {brands.length > 0 ? (form.data.brand_id === '' ? t('couriers.for_any_brand') : t('couriers.for_this_brand')) : ''}
                    </label>
                    {brands.length > 0 && <p className="text-muted-foreground text-xs">{t('couriers.brand_routing_hint')}</p>}
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={form.processing}>
                            {editing ? t('couriers.save') : t('couriers.use_here')}
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
                                {t('couriers.stop_using')}
                                {brands.length > 0
                                    ? t('couriers.for_brand', { brand: editing.brand_id === null ? t('couriers.any_brand') : brandName(editing) })
                                    : ''}
                            </Button>
                        )}
                    </div>
                </form>
            )}
        </div>
    );
}

/** Copies a value the owner must paste into the courier's own website. */
function CopyButton({ value }: { value: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard
            ?.writeText(value)
            .then(() => {
                setCopied(true);
                window.setTimeout(() => setCopied(false), 2000);
            })
            .catch(() => setCopied(false));
    };

    return (
        <Button type="button" variant="outline" size="sm" onClick={copy}>
            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            {copied ? t('couriers.copied') : t('couriers.copy')}
        </Button>
    );
}

/**
 * The pairing guard (11 Oct 2026): what happened the last time the courier
 * tried to connect its webhook, and when it last sent an update.
 */
function WebhookStatusLine({ status }: { status: WebhookStatus }) {
    const { t } = useTranslation();
    const date = status.last_handshake_at ?? '';

    return (
        <div className="space-y-0.5 text-xs">
            {status.last_handshake_at === null ? (
                <p className="text-muted-foreground">{t('couriers.webhook_never_tried')}</p>
            ) : status.last_handshake_ok ? (
                <p className="text-emerald-700 dark:text-emerald-300">{t('couriers.webhook_paired', { date })}</p>
            ) : (
                <p className="text-destructive">
                    {status.problem === 'missing_echo'
                        ? t('couriers.webhook_failed_missing_echo', { date })
                        : status.problem === 'bad_signature'
                          ? t('couriers.webhook_failed_bad_signature', { date })
                          : t('couriers.webhook_failed', { date })}
                </p>
            )}
            <p className="text-muted-foreground">
                {status.last_event_at === null
                    ? t('couriers.webhook_no_event')
                    : t('couriers.webhook_last_event', { date: showDate(status.last_event_at) })}
            </p>
        </div>
    );
}

export default function CourierSettings() {
    const { providers, accounts, moneyAccounts, brands = [], revealedSecret, errors } = usePage<PageProps>().props;
    const { t, tr } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: t('couriers.title'), href: '/settings/couriers' },
    ];
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
            <Head title={t('couriers.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall title={t('couriers.title')} description={t('couriers.description')} />
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href={route('couriers.settings.locations.index')}>{t('couriers.locations_and_bands')}</Link>
                            </Button>
                            {!adding && <Button onClick={() => setAdding(true)}>{t('couriers.add_account')}</Button>}
                        </div>
                    </div>

                    {errors.activate && <InputError message={errors.activate} />}

                    {adding && <AccountForm providers={providers} onDone={() => setAdding(false)} />}

                    {accounts.length === 0 && !adding && (
                        <div className="border-border flex flex-col items-start gap-2 rounded-md border border-dashed p-6">
                            <Truck className="text-muted-foreground size-6" />
                            <p className="font-medium">{t('couriers.none_title')}</p>
                            <ol className="text-muted-foreground list-decimal space-y-1 pl-5 text-sm">
                                <li>{t('couriers.none_step1')}</li>
                                <li>{t('couriers.none_step2')}</li>
                                <li>{t('couriers.none_step3')}</li>
                                <li>{t('couriers.none_step4')}</li>
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
                                                {a.environment === 'live' ? t('couriers.live') : t('couriers.sandbox')}
                                            </span>
                                            <span
                                                className={cn(
                                                    'rounded-full px-2 py-0.5',
                                                    a.is_active
                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {a.is_active ? t('couriers.active') : t('couriers.off')}
                                            </span>
                                            {a.webhook_status.needs_echo && (
                                                <span
                                                    className="rounded-full bg-red-100 px-2 py-0.5 text-red-800 dark:bg-red-900/40 dark:text-red-200"
                                                    title={t('couriers.echo_missing_title')}
                                                >
                                                    {t('couriers.echo_missing_badge')}
                                                </span>
                                            )}
                                            <span className="text-muted-foreground">{a.base_url}</span>
                                        </div>
                                        {a.last_test_at === null ? (
                                            <p className="text-muted-foreground text-xs">{t('couriers.not_tested')}</p>
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
                                                    ? t('couriers.passed_on', { date: showDate(a.last_test_at), message: a.last_test_message ?? '' })
                                                    : t('couriers.passed_stale', { date: showDate(a.last_test_at) })}
                                            </p>
                                        ) : (
                                            <p className="text-destructive text-xs">
                                                {t('couriers.failed_on', { date: showDate(a.last_test_at), message: a.last_test_message ?? '' })}
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
                                            {busyId === a.id ? t('couriers.testing') : t('couriers.test_connection')}
                                        </Button>
                                        {a.is_active ? (
                                            <Button variant="outline" size="sm" onClick={() => post('couriers.settings.deactivate', a.id)}>
                                                {t('couriers.deactivate')}
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                disabled={!a.can_activate}
                                                title={a.can_activate ? undefined : t('couriers.activate_needs_test')}
                                                onClick={() => post('couriers.settings.activate', a.id)}
                                            >
                                                {t('couriers.activate')}
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="sm" onClick={() => setEditingId(editingId === a.id ? null : a.id)}>
                                            {editingId === a.id ? t('couriers.close') : t('couriers.edit')}
                                        </Button>
                                    </div>
                                </div>

                                {editingId === a.id && <AccountForm providers={providers} account={a} onDone={() => setEditingId(null)} />}

                                {a.test_is_current && a.stores.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium">
                                            {t('couriers.stores_returned')}
                                            {a.last_test_city_count !== null ? t('couriers.city_count', { count: a.last_test_city_count }) : ''}
                                        </p>
                                        <ul className="text-muted-foreground text-sm">
                                            {a.stores.map((s) => (
                                                <li key={s.id}>
                                                    {s.name} ({s.id}){s.active ? '' : t('couriers.inactive')}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <div className="grid gap-6 md:grid-cols-2">
                                    <BusinessLink account={a} moneyAccounts={moneyAccounts} brands={brands} />

                                    <div className="space-y-2">
                                        <p className="text-sm font-medium">{t('couriers.webhook')}</p>
                                        <p className="text-muted-foreground text-xs">{t('couriers.webhook_help')}</p>
                                        <WebhookStatusLine status={a.webhook_status} />
                                        <p className="text-muted-foreground text-xs">{t('couriers.webhook_url')}</p>
                                        <div className="flex items-start gap-2">
                                            <code className="bg-muted block min-w-0 flex-1 rounded px-2 py-1 text-xs break-all">{a.webhook_url}</code>
                                            <CopyButton value={a.webhook_url} />
                                        </div>
                                        {revealedSecret?.account_id === a.id ? (
                                            <div className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs dark:border-amber-800 dark:bg-amber-950/40">
                                                <p className="font-medium">{t('couriers.new_secret_once')}</p>
                                                <div className="flex items-start gap-2">
                                                    <code className="min-w-0 flex-1 break-all">{revealedSecret.secret}</code>
                                                    <CopyButton value={revealedSecret.secret} />
                                                </div>
                                            </div>
                                        ) : (
                                            <p className="text-muted-foreground text-xs">{t('couriers.secret_shown_once')}</p>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                if (window.confirm(t('couriers.rotate_confirm'))) post('couriers.settings.rotate-secret', a.id);
                                            }}
                                        >
                                            {t('couriers.rotate_secret')}
                                        </Button>
                                    </div>
                                </div>

                                <div className="space-y-1">
                                    <p className="text-sm font-medium">{t('couriers.auto_post_title')}</p>
                                    <label className="text-muted-foreground flex items-center gap-2 text-xs">
                                        <input type="checkbox" checked={a.auto_post_outcomes} disabled readOnly />
                                        {t('couriers.auto_post_help', { state: a.auto_post_outcomes ? t('couriers.on') : t('couriers.off') })}
                                    </label>
                                    {a.auto_post_outcomes && (
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => router.post(route('couriers.settings.auto-post', a.id), { on: false })}
                                        >
                                            {t('couriers.emergency_off')}
                                        </Button>
                                    )}
                                </div>

                                <p className="text-muted-foreground text-xs">{a.created_by ? t('couriers.added_by', { name: a.created_by }) : ''}</p>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-3">
                        <h3 className="text-sm font-medium">{t('couriers.known')}</h3>
                        <div className="grid gap-3 md:grid-cols-2">
                            {providers.map((p) => (
                                <div key={p.code} className="border-border rounded-md border p-3 text-sm">
                                    <p className="font-medium">
                                        {p.name}{' '}
                                        <span className="text-muted-foreground text-xs font-normal">
                                            {p.connected ? t('couriers.connected') : t('couriers.not_connected')}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {p.connected
                                            ? Object.entries(p.capabilities)
                                                  .map(
                                                      ([k, v]) =>
                                                          `${tr(`couriers.cap.${k}`, CAPABILITY_LABELS[k] ?? k)}: ${v === true ? t('couriers.yes') : v === false ? t('couriers.no') : t('couriers.unknown')}`,
                                                  )
                                                  .join(' · ')
                                            : t('couriers.api_not_checked')}
                                    </p>
                                </div>
                            ))}
                        </div>
                        {notConnected.length > 0 && (
                            <p className="text-muted-foreground text-xs">
                                {t('couriers.can_connect_later', { names: notConnected.map((p) => p.name).join(', ') })}
                            </p>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
