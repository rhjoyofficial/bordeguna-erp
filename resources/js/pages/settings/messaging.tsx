import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { type FormDataConvertible } from '@inertiajs/core';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * ⚠ Every field here is a plain string, never null, even where the server
 * stores null. An Inertia form field that can be null is one an <Input> cannot
 * render, and the round trip through the controller turns '' back into null.
 * The index signatures are what useForm's FormDataType constraint requires of a
 * nested object; the named properties still win for the keys we know.
 */
interface SuccessRule {
    [key: string]: FormDataConvertible;
    mode: string;
    path: string;
    values: string[];
}

interface GatewayConfig {
    [key: string]: FormDataConvertible;
    endpoint: string;
    method: string;
    auth: string;
    recipient_field: string;
    message_field: string;
    sender_field: string;
    number_format: string;
    credential_map: Record<string, string>;
    extra_params: Record<string, string>;
    unicode_params: Record<string, string>;
    success: SuccessRule;
    error_path: string;
    message_id_path: string;
    balance_endpoint: string;
    balance_path: string;
}

interface ProviderForm {
    [key: string]: FormDataConvertible;
    name: string;
    channel: string;
    driver: string;
    sender_id: string;
    priority: number;
    cost_per_part: string;
    credentials: Record<string, string>;
    config: GatewayConfig;
}

interface Provider {
    id: number;
    channel: string;
    driver: string;
    name: string;
    sender_id: string | null;
    is_active: boolean;
    priority: number;
    cost_per_part: string;
    credentials: Record<string, string>;
    config: Partial<GatewayConfig>;
}

interface Preset {
    key: string;
    label: string;
    confidence: string;
    note: string;
    credential_fields: string[];
    // ⚠ The server sends nulls for the fields a gateway does not use.
    config: Partial<Record<keyof GatewayConfig, unknown>>;
}

interface TestResult {
    provider_id: number;
    accepted: boolean;
    status: number | null;
    body: string;
    error: string | null;
    message_id: string | null;
    balance?: string | null;
    sent: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Messaging', href: '/crm/settings/messaging' }];

const BLANK_CONFIG: GatewayConfig = {
    endpoint: '',
    method: 'post_form',
    auth: 'params',
    recipient_field: 'to',
    message_field: 'message',
    sender_field: '',
    number_format: 'msisdn88',
    credential_map: { api_key: 'api_key' },
    extra_params: {},
    unicode_params: {},
    success: { mode: 'http_ok', path: '', values: [] },
    error_path: '',
    message_id_path: '',
    balance_endpoint: '',
    balance_path: '',
};

const EMPTY: ProviderForm = {
    name: '',
    channel: 'sms',
    driver: '',
    sender_id: '',
    priority: 100,
    cost_per_part: '0',
    credentials: {},
    config: BLANK_CONFIG,
};

/** A stored or preset config, with every null turned back into an empty box. */
const asConfig = (stored: Record<string, unknown> | undefined | null): GatewayConfig => {
    const merged: Record<string, unknown> = { ...BLANK_CONFIG };

    Object.entries(stored ?? {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
            merged[key] = value;
        }
    });

    const success = (stored?.success ?? {}) as Record<string, unknown>;

    merged.success = {
        mode: (success.mode as string) ?? 'http_ok',
        path: (success.path as string) ?? '',
        values: (success.values as string[]) ?? [],
    };

    return merged as GatewayConfig;
};

/** `a=1` per line, which is how every gateway documents its own extras. */
const pairsToText = (pairs: Record<string, string>) =>
    Object.entries(pairs ?? {})
        .map(([k, v]) => `${k}=${v}`)
        .join('\n');

const textToPairs = (text: string) => {
    const out: Record<string, string> = {};

    text.split('\n').forEach((line) => {
        const at = line.indexOf('=');

        if (at > 0) {
            out[line.slice(0, at).trim()] = line.slice(at + 1).trim();
        }
    });

    return out;
};

export default function MessagingSettings({
    providers,
    drivers,
    presets,
    approvalAbove,
    mailer,
}: {
    providers: Provider[];
    drivers: { value: string; label: string; channel: string }[];
    presets: Preset[];
    approvalAbove: number;
    mailer: string;
}) {
    const testResult = (usePage().props as unknown as { test_result?: TestResult }).test_result;

    const [editing, setEditing] = useState<Provider | null>(null);
    const [presetKey, setPresetKey] = useState('');
    const [testingId, setTestingId] = useState<number | null>(null);

    const form = useForm<ProviderForm>(EMPTY);
    const thresholdForm = useForm({ approval_above: String(approvalAbove) });
    const testForm = useForm({ to: '', body: 'Bor de Guna test message' });

    const config = form.data.config;
    const isGateway = form.data.driver === 'http_sms';

    const setConfig = (patch: Partial<GatewayConfig>) => form.setData('config', { ...config, ...patch });

    /** Picking a gateway fills every field in. Nothing is locked afterwards. */
    const applyPreset = (key: string) => {
        setPresetKey(key);

        const preset = presets.find((p) => p.key === key);

        if (!preset) {
            return;
        }

        const credentials: Record<string, string> = {};
        preset.credential_fields.forEach((f) => (credentials[f] = ''));

        form.setData({
            ...form.data,
            name: form.data.name || preset.label,
            config: asConfig(preset.config as Record<string, unknown>),
            credentials,
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('crm.settings.messaging.update', editing.id), { preserveScroll: true, onSuccess: () => cancel() });

            return;
        }

        form.post(route('crm.settings.messaging.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const cancel = () => {
        setEditing(null);
        setPresetKey('');
        form.clearErrors();
        form.reset();
    };

    const edit = (provider: Provider) => {
        setEditing(provider);
        setPresetKey('');
        form.clearErrors();

        const credentials: Record<string, string> = {};
        Object.keys(provider.config?.credential_map ?? provider.credentials ?? {}).forEach((k) => (credentials[k] = ''));

        form.setData({
            name: provider.name,
            channel: provider.channel,
            driver: provider.driver,
            sender_id: provider.sender_id ?? '',
            priority: provider.priority,
            cost_per_part: provider.cost_per_part,
            // ⚠ Always blank. The server never sends a secret back, and a blank
            // field means "leave it alone" rather than "erase it".
            credentials,
            config: asConfig(provider.config as Record<string, unknown>),
        });
    };

    const runTest = (providerId: number) => {
        testForm.post(route('crm.settings.messaging.test', providerId), { preserveScroll: true });
    };

    const forChannel = drivers.filter((d) => d.channel === form.data.channel);
    const activePreset = presets.find((p) => p.key === presetKey);

    const credentialRows = Object.entries(config.credential_map ?? {});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Messaging providers" />

            <SettingsLayout wide>
                <div className="space-y-6">
                    <HeadingSmall title="Messaging" />

                    {mailer === 'log' && (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950">
                            The application mailer is set to <strong>log</strong>, so no email actually leaves the server.
                        </div>
                    )}

                    {/* ── The spending brake ─────────────────────────────── */}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            thresholdForm.put(route('crm.settings.messaging.settings'), { preserveScroll: true });
                        }}
                        className="flex flex-wrap items-end gap-4 rounded-lg border p-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="approval_above">A send needs approving above</Label>
                            <Input
                                id="approval_above"
                                type="number"
                                min={0}
                                className="w-40"
                                placeholder="Empty: every send"
                                value={thresholdForm.data.approval_above}
                                onChange={(e) => thresholdForm.setData('approval_above', e.target.value)}
                            />
                            <InputError message={thresholdForm.errors.approval_above} />
                        </div>
                        <Button type="submit" variant="secondary" disabled={thresholdForm.processing}>
                            Save threshold
                        </Button>
                    </form>

                    {/* ── Add or edit a provider ─────────────────────────── */}
                    <form onSubmit={submit} className="space-y-4 rounded-lg border p-4">
                        {/* Every box as wide as what it holds (30 Aug 2026 audit): a
                            cost is a money box, a priority is a digit, a URL grows. */}
                        <FormRow>
                            <Field label="Name" htmlFor="name" size="md" error={form.errors.name} required>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Sends" htmlFor="channel" required>
                                <SearchSelect
                                    id="channel"
                                    value={form.data.channel}
                                    onChange={(e) => form.setData({ ...form.data, channel: e.target.value, driver: '' })}
                                >
                                    <option value="sms">SMS</option>
                                    <option value="email">Email</option>
                                </SearchSelect>
                            </Field>
                            <Field label="Through" htmlFor="driver" error={form.errors.driver} required>
                                <SearchSelect id="driver" value={form.data.driver} onChange={(e) => form.setData('driver', e.target.value)}>
                                    <option value="">Choose</option>
                                    {forChannel.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label={form.data.channel === 'sms' ? 'Sender ID (masking)' : 'From address'} htmlFor="sender_id" size="md">
                                <Input id="sender_id" value={form.data.sender_id} onChange={(e) => form.setData('sender_id', e.target.value)} />
                            </Field>
                            <Field label="Cost per part" htmlFor="cost_per_part" size="sm" error={form.errors.cost_per_part} required>
                                <Input
                                    id="cost_per_part"
                                    inputMode="decimal"
                                    value={form.data.cost_per_part}
                                    onChange={(e) => form.setData('cost_per_part', e.target.value)}
                                />
                            </Field>
                            <Field label="Priority" htmlFor="priority" size="xs" required>
                                <Input
                                    id="priority"
                                    type="number"
                                    min={1}
                                    value={form.data.priority}
                                    onChange={(e) => form.setData('priority', Number(e.target.value))}
                                />
                            </Field>
                        </FormRow>

                        {isGateway && (
                            <div className="space-y-4 rounded-lg border border-dashed p-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="preset">Start from a known gateway</Label>
                                    <SearchSelect fit id="preset" value={presetKey} onChange={(e) => applyPreset(e.target.value)}>
                                        <option value="">Choose one, or fill the fields in by hand</option>
                                        {presets.map((p) => (
                                            <option key={p.key} value={p.key}>
                                                {p.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    {activePreset && (
                                        <p
                                            className={`text-xs ${activePreset.confidence === 'documented' ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400'}`}
                                        >
                                            {activePreset.confidence === 'documented'
                                                ? 'Wired from the published documentation. '
                                                : 'Partly guessed - send a test and set the success rule from the reply. '}
                                            {activePreset.note}
                                        </p>
                                    )}
                                </div>

                                <FormRow>
                                    <Field
                                        label="Endpoint"
                                        htmlFor="endpoint"
                                        size="grow"
                                        className="min-w-80"
                                        error={(form.errors as Record<string, string>)['config.endpoint']}
                                    >
                                        <Input
                                            id="endpoint"
                                            placeholder="https://bulksmsbd.net/api/smsapi"
                                            value={config.endpoint}
                                            onChange={(e) => setConfig({ endpoint: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Sent as" htmlFor="method">
                                        <SearchSelect id="method" value={config.method} onChange={(e) => setConfig({ method: e.target.value })}>
                                            <option value="post_form">POST form</option>
                                            <option value="post_json">POST JSON</option>
                                            <option value="get">GET</option>
                                        </SearchSelect>
                                    </Field>
                                </FormRow>
                                <FormRow>
                                    <Field label="Number field" htmlFor="recipient_field" size="sm">
                                        <Input
                                            id="recipient_field"
                                            value={config.recipient_field}
                                            onChange={(e) => setConfig({ recipient_field: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Message field" htmlFor="message_field" size="sm">
                                        <Input
                                            id="message_field"
                                            value={config.message_field}
                                            onChange={(e) => setConfig({ message_field: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Sender ID field" htmlFor="sender_field" size="sm">
                                        <Input
                                            id="sender_field"
                                            value={config.sender_field ?? ''}
                                            onChange={(e) => setConfig({ sender_field: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Number written as" htmlFor="number_format">
                                        <SearchSelect
                                            id="number_format"
                                            value={config.number_format}
                                            onChange={(e) => setConfig({ number_format: e.target.value })}
                                        >
                                            <option value="msisdn88">8801711000000</option>
                                            <option value="local0">01711000000</option>
                                            <option value="plus88">+8801711000000</option>
                                            <option value="raw">exactly as stored</option>
                                        </SearchSelect>
                                    </Field>
                                    <Field label="Credentials go in" htmlFor="auth">
                                        <SearchSelect id="auth" value={config.auth} onChange={(e) => setConfig({ auth: e.target.value })}>
                                            <option value="params">the parameters</option>
                                            <option value="basic">HTTP basic auth</option>
                                            <option value="bearer">a bearer token</option>
                                            <option value="header">request headers</option>
                                        </SearchSelect>
                                    </Field>
                                </FormRow>

                                {/* Secrets: what it is called here, what it is called on the wire, and its value. */}
                                <div className="space-y-2">
                                    <Label>Keys</Label>
                                    {credentialRows.map(([secret, wire]) => (
                                        <div key={secret} className="flex flex-wrap gap-2">
                                            <Input
                                                className="w-40"
                                                aria-label={`${secret} name`}
                                                value={secret}
                                                onChange={(e) => {
                                                    const map = { ...config.credential_map };
                                                    const value = form.data.credentials[secret] ?? '';
                                                    delete map[secret];
                                                    map[e.target.value] = wire;
                                                    const creds = { ...form.data.credentials };
                                                    delete creds[secret];
                                                    creds[e.target.value] = value;
                                                    form.setData({ ...form.data, config: { ...config, credential_map: map }, credentials: creds });
                                                }}
                                            />
                                            <Input
                                                className="w-48"
                                                aria-label={`${secret} sent as`}
                                                placeholder="sent to the gateway as"
                                                value={wire}
                                                onChange={(e) =>
                                                    setConfig({ credential_map: { ...config.credential_map, [secret]: e.target.value } })
                                                }
                                            />
                                            <Input
                                                className="min-w-56 flex-1"
                                                aria-label={`${secret} value`}
                                                type="password"
                                                autoComplete="off"
                                                placeholder={editing ? 'leave blank to keep' : 'paste the key'}
                                                value={form.data.credentials[secret] ?? ''}
                                                onChange={(e) => form.setData('credentials', { ...form.data.credentials, [secret]: e.target.value })}
                                            />
                                        </div>
                                    ))}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setConfig({ credential_map: { ...config.credential_map, ['key' + credentialRows.length]: '' } })
                                        }
                                    >
                                        Add another key
                                    </Button>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="extra_params">Always send these too</Label>
                                        <textarea
                                            id="extra_params"
                                            rows={3}
                                            className="border-input bg-background rounded-md border p-2 font-mono text-xs"
                                            placeholder="type=text"
                                            value={pairsToText(config.extra_params)}
                                            onChange={(e) => setConfig({ extra_params: textToPairs(e.target.value) })}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="unicode_params">And these when the message is Bangla</Label>
                                        <textarea
                                            id="unicode_params"
                                            rows={3}
                                            className="border-input bg-background rounded-md border p-2 font-mono text-xs"
                                            placeholder="type=unicode"
                                            value={pairsToText(config.unicode_params)}
                                            onChange={(e) => setConfig({ unicode_params: textToPairs(e.target.value) })}
                                        />
                                    </div>
                                </div>

                                <FormRow>
                                    <Field label="It worked when" htmlFor="success_mode">
                                        <SearchSelect
                                            id="success_mode"
                                            value={config.success?.mode ?? 'http_ok'}
                                            onChange={(e) => setConfig({ success: { ...config.success, mode: e.target.value } })}
                                        >
                                            <option value="json_in">a field in the reply equals</option>
                                            <option value="body_contains">the reply contains</option>
                                            <option value="http_ok">the reply is any HTTP 2xx</option>
                                        </SearchSelect>
                                    </Field>
                                    <Field label="Field" htmlFor="success_path" size="md">
                                        <Input
                                            id="success_path"
                                            placeholder="response_code"
                                            disabled={config.success?.mode !== 'json_in'}
                                            value={config.success?.path ?? ''}
                                            onChange={(e) => setConfig({ success: { ...config.success, path: e.target.value } })}
                                        />
                                    </Field>
                                    <Field label="Any of these values" htmlFor="success_values" size="md">
                                        <Input
                                            id="success_values"
                                            placeholder="202"
                                            disabled={config.success?.mode === 'http_ok'}
                                            value={(config.success?.values ?? []).join(', ')}
                                            onChange={(e) =>
                                                setConfig({
                                                    success: {
                                                        ...config.success,
                                                        values: e.target.value
                                                            .split(',')
                                                            .map((v) => v.trim())
                                                            .filter(Boolean),
                                                    },
                                                })
                                            }
                                        />
                                    </Field>
                                </FormRow>

                                {config.success?.mode === 'http_ok' && (
                                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-900 dark:bg-amber-950">
                                        Most Bangladeshi gateways answer <strong>HTTP 200 whether they accepted the message or refused it</strong>,
                                        and put the truth in the reply body. On this setting a refused message will be recorded as sent. Send a test,
                                        read the reply below, then set the field and value.
                                    </p>
                                )}

                                <FormRow>
                                    <Field label="Error message field" htmlFor="error_path" size="md">
                                        <Input
                                            id="error_path"
                                            value={config.error_path ?? ''}
                                            onChange={(e) => setConfig({ error_path: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Message ID field" htmlFor="message_id_path" size="md">
                                        <Input
                                            id="message_id_path"
                                            value={config.message_id_path ?? ''}
                                            onChange={(e) => setConfig({ message_id_path: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Balance endpoint" htmlFor="balance_endpoint" size="grow">
                                        <Input
                                            id="balance_endpoint"
                                            value={config.balance_endpoint ?? ''}
                                            onChange={(e) => setConfig({ balance_endpoint: e.target.value })}
                                        />
                                    </Field>
                                    <Field label="Balance field" htmlFor="balance_path" size="md">
                                        <Input
                                            id="balance_path"
                                            value={config.balance_path ?? ''}
                                            onChange={(e) => setConfig({ balance_path: e.target.value })}
                                        />
                                    </Field>
                                </FormRow>
                            </div>
                        )}

                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save provider' : 'Add provider'}
                            </Button>
                            {editing && (
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>

                    {/* ── What is configured ─────────────────────────────── */}
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Provider</th>
                                    <th className="p-3">Sends</th>
                                    <th className="p-3">Keys</th>
                                    <th className="p-3">Cost per part</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {providers.map((provider) => (
                                    <tr key={provider.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{provider.name}</div>
                                            {provider.sender_id && <div className="text-muted-foreground text-xs">{provider.sender_id}</div>}
                                            {provider.config?.endpoint && (
                                                <div className="text-muted-foreground font-mono text-xs">{provider.config.endpoint}</div>
                                            )}
                                        </td>
                                        <td className="p-3 uppercase">{provider.channel}</td>
                                        <td className="p-3">
                                            {Object.keys(provider.credentials).length === 0 ? (
                                                <span className="text-muted-foreground">none saved</span>
                                            ) : (
                                                Object.entries(provider.credentials).map(([key, value]) => (
                                                    <div key={key} className="text-xs">
                                                        {key}: {value}
                                                    </div>
                                                ))
                                            )}
                                        </td>
                                        <td className="p-3">{provider.cost_per_part}</td>
                                        <td className="p-3">
                                            <Badge variant={provider.is_active ? 'secondary' : 'outline'}>{provider.is_active ? 'On' : 'Off'}</Badge>
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            <Button variant="ghost" size="sm" onClick={() => edit(provider)}>
                                                Edit
                                            </Button>
                                            {provider.driver === 'http_sms' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setTestingId(testingId === provider.id ? null : provider.id)}
                                                >
                                                    Test
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(route('crm.settings.messaging.toggle', provider.id), {}, { preserveScroll: true })
                                                }
                                            >
                                                {provider.is_active ? 'Switch off' : 'Switch on'}
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {providers.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                            No providers yet, so nothing can be sent.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* ── One real message, and exactly what came back ────── */}
                    {testingId !== null && (
                        <div className="space-y-3 rounded-lg border p-4">
                            <HeadingSmall
                                title="Send one test message"
                                description="Goes to a real handset and costs one message."
                            />
                            <FormRow>
                                <Field label="To" htmlFor="test_to" size="md" error={testForm.errors.to} required>
                                    <Input
                                        id="test_to"
                                        placeholder="01711000000"
                                        value={testForm.data.to}
                                        onChange={(e) => testForm.setData('to', e.target.value)}
                                    />
                                </Field>
                                <Field label="Message" htmlFor="test_body" size="grow" required>
                                    <Input id="test_body" value={testForm.data.body} onChange={(e) => testForm.setData('body', e.target.value)} />
                                </Field>
                            </FormRow>
                            <div className="flex gap-2">
                                <Button onClick={() => runTest(testingId)} disabled={testForm.processing}>
                                    Send the test
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => router.post(route('crm.settings.messaging.balance', testingId), {}, { preserveScroll: true })}
                                >
                                    Check balance
                                </Button>
                            </div>

                            {testResult && testResult.provider_id === testingId && (
                                <div className="space-y-2 rounded-md border p-3">
                                    <div className="flex items-center gap-2">
                                        <Badge variant={testResult.accepted ? 'secondary' : 'destructive'}>
                                            {testResult.accepted ? 'Accepted' : 'Not accepted'}
                                        </Badge>
                                        {testResult.status !== null && <span className="text-xs">HTTP {testResult.status}</span>}
                                        {testResult.message_id && <span className="text-xs">id {testResult.message_id}</span>}
                                        {testResult.balance != null && <span className="text-xs">balance {testResult.balance}</span>}
                                    </div>
                                    {testResult.error && <p className="text-destructive text-sm">{testResult.error}</p>}
                                    <div>
                                        <Label className="text-xs">What the gateway replied</Label>
                                        <pre className="bg-muted mt-1 overflow-x-auto rounded p-2 text-xs">{testResult.body || '(nothing)'}</pre>
                                    </div>
                                    {Object.keys(testResult.sent ?? {}).length > 0 && (
                                        <div>
                                            <Label className="text-xs">What was sent (keys hidden)</Label>
                                            <pre className="bg-muted mt-1 overflow-x-auto rounded p-2 text-xs">
                                                {JSON.stringify(testResult.sent, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
