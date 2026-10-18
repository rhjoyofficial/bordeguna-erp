import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { showDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Bot, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Credential = {
    id: number;
    provider: string;
    label: string | null;
    model: string | null;
    hint: string | null;
    is_active: boolean;
    verified_at: string | null;
    verify_error: string | null;
    updated_at: string | null;
    created_by: string | null;
};

type PageProps = {
    credentials: Credential[];
    providers: Record<string, string>;
    supported: Record<string, boolean>;
    /** provider => { model id: display name }, asked of the provider itself. */
    models: Record<string, Record<string, string>>;
    envFallback: Record<string, boolean>;
    activeProvider: string | null;
    /** The master switch. Off means the floating button exists for nobody. */
    assistantEnabled: boolean;
    /** Every language on offer. */
    languages: { code: string; label: string; native: string }[];
    /** The two currently switched on, which become the chat toggle. */
    activeLanguages: string[];
    requiredLanguages: number;
    /** Null means no ceiling. */
    monthlyCap: number | null;
    usedThisMonth: number;
    /** What the assistant is called, what it looks like, how long a message may be. */
    identity: {
        name: string;
        avatar: string | null;
        charLimit: number;
        defaultName: string;
        minCharLimit: number;
        maxCharLimit: number;
    };
    flash?: { success?: string };
};

export default function AiProviders() {
    const {
        credentials,
        providers,
        supported,
        models,
        envFallback,
        activeProvider,
        assistantEnabled,
        languages,
        activeLanguages,
        requiredLanguages,
        monthlyCap,
        usedThisMonth,
        identity,
    } = usePage<PageProps>().props;

    const prefs = useForm<{ languages: string[]; monthly_cap: string }>({
        languages: activeLanguages,
        monthly_cap: monthlyCap === null ? '' : String(monthlyCap),
    });

    // ⚠ Picking a third replaces the OLDEST, so the control always holds exactly
    // two and never has to refuse a click. "Deselect one first" is a rule the
    // interface can simply not need.
    const toggleLanguage = (code: string) => {
        const current = prefs.data.languages;
        if (current.includes(code)) return;
        prefs.setData('languages', [current[1] ?? current[0], code].filter(Boolean).slice(-2));
    };
    // The name, the face and the input ceiling. One form, because they are one
    // decision: what this thing is to the people who talk to it.
    const identityForm = useForm<{ name: string; char_limit: string; avatar: File | null; remove_avatar: boolean }>({
        name: identity.name === identity.defaultName ? '' : identity.name,
        char_limit: String(identity.charLimit),
        avatar: null,
        remove_avatar: false,
    });

    // The picture the browser is holding, not the one on the server: it lets
    // somebody see what they picked before they save it.
    const [avatarPreview, setAvatarPreview] = useState<string | null>(null);

    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const [testingId, setTestingId] = useState<number | null>(null);

    const form = useForm({
        provider: 'anthropic',
        label: '',
        model: '',
        secret: '',
    });

    // What the chosen provider says it can run. Empty until a key for that
    // provider exists, because the list is read using the key.
    const choices = models[form.data.provider] ?? {};

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/settings/ai-providers', {
            preserveScroll: true,
            // The key is cleared from browser memory the moment it is accepted.
            onSuccess: () => form.reset('secret', 'label', 'model'),
        });
    };

    return (
        <AppLayout>
            <Head title="AI providers" />
            <SettingsLayout>
                {/* ⚠ The master switch, above the keys, because it is the thing
                    that decides whether any of this is reachable at all. */}
                <div className="flex flex-wrap items-start justify-between gap-4 rounded-lg border p-4">
                    <div>
                        <p className="font-medium">Assistant panel</p>
                        <p className="text-muted-foreground max-w-xl text-sm">{assistantEnabled ? 'On' : 'Off'}</p>
                    </div>
                    <Button
                        variant={assistantEnabled ? 'outline' : 'default'}
                        onClick={() => router.patch(route('ai-providers.assistant'), { enabled: !assistantEnabled }, { preserveScroll: true })}
                    >
                        {assistantEnabled ? 'Switch off' : 'Switch on'}
                    </Button>
                </div>

                <div className="space-y-6">
                    <HeadingSmall title="AI providers" />

                    <p className="text-muted-foreground text-sm">
                        {activeProvider
                            ? `Messages are answered by ${providers[activeProvider] ?? activeProvider}.`
                            : 'No provider can answer yet. Add a key below and the assistant switches itself on.'}
                    </p>

                    {/* Which two languages the chat toggle offers, and the ceiling. */}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            prefs.patch(route('ai-providers.preferences'), { preserveScroll: true });
                        }}
                        className="border-border space-y-4 rounded-md border p-4"
                    >
                        <div>
                            <p className="font-medium">
                                Chat languages
                                <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                    *
                                </span>
                            </p>
                            <p className="text-muted-foreground max-w-2xl text-sm">Choose exactly {requiredLanguages}.</p>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {languages.map((option) => {
                                const on = prefs.data.languages.includes(option.code);

                                return (
                                    <button
                                        key={option.code}
                                        type="button"
                                        onClick={() => toggleLanguage(option.code)}
                                        aria-pressed={on}
                                        className={cn(
                                            'rounded-full border px-3 py-1.5 text-sm transition-colors',
                                            on ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-accent',
                                        )}
                                    >
                                        {option.native} <span className="opacity-70">({option.label})</span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={prefs.errors.languages} />

                        <div className="flex flex-wrap items-end gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="monthly_cap">Messages allowed each month</Label>
                                <Input
                                    id="monthly_cap"
                                    type="number"
                                    min={1}
                                    className="w-44"
                                    placeholder="No limit"
                                    value={prefs.data.monthly_cap}
                                    onChange={(e) => prefs.setData('monthly_cap', e.target.value)}
                                />
                                <InputError message={prefs.errors.monthly_cap} />
                            </div>
                            <Button type="submit" variant="secondary" disabled={prefs.processing}>
                                Save preferences
                            </Button>
                            <p className="text-muted-foreground max-w-md text-xs">
                                <strong>{usedThisMonth}</strong> used this month.
                            </p>
                        </div>
                    </form>

                    {/* Name, face, and the length of one message. */}
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            identityForm.post(route('ai-providers.identity'), {
                                preserveScroll: true,
                                forceFormData: true,
                                onSuccess: () => {
                                    identityForm.setData('avatar', null);
                                    identityForm.setData('remove_avatar', false);
                                    setAvatarPreview(null);
                                },
                            });
                        }}
                        className="border-border space-y-4 rounded-md border p-4"
                    >
                        <p className="font-medium">Name and picture</p>

                        <div className="flex flex-wrap items-end gap-4">
                            <div className="flex items-center gap-3">
                                <div className="bg-muted text-muted-foreground flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-full border">
                                    {avatarPreview || (identity.avatar && !identityForm.data.remove_avatar) ? (
                                        <img src={avatarPreview ?? identity.avatar ?? undefined} alt="" className="size-full object-cover" />
                                    ) : (
                                        <Bot className="size-6" />
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="avatar">Picture</Label>
                                    <Input
                                        id="avatar"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="w-64"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] ?? null;
                                            identityForm.setData('avatar', file);
                                            identityForm.setData('remove_avatar', false);
                                            setAvatarPreview(file ? URL.createObjectURL(file) : null);
                                        }}
                                    />
                                    <InputError message={identityForm.errors.avatar} />
                                    {identity.avatar && !identityForm.data.remove_avatar && (
                                        <button
                                            type="button"
                                            className="text-muted-foreground hover:text-destructive text-left text-xs underline"
                                            onClick={() => {
                                                identityForm.setData('remove_avatar', true);
                                                identityForm.setData('avatar', null);
                                                setAvatarPreview(null);
                                            }}
                                        >
                                            Remove the picture
                                        </button>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="assistant_name">Name</Label>
                                <Input
                                    id="assistant_name"
                                    className="w-56"
                                    maxLength={40}
                                    placeholder={identity.defaultName}
                                    value={identityForm.data.name}
                                    onChange={(e) => identityForm.setData('name', e.target.value)}
                                />
                                <InputError message={identityForm.errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="char_limit">Longest message</Label>
                                <Input
                                    id="char_limit"
                                    type="number"
                                    min={identity.minCharLimit}
                                    max={identity.maxCharLimit}
                                    className="w-44"
                                    placeholder={`${identity.minCharLimit} to ${identity.maxCharLimit}`}
                                    value={identityForm.data.char_limit}
                                    onChange={(e) => identityForm.setData('char_limit', e.target.value)}
                                />
                                <InputError message={identityForm.errors.char_limit} />
                            </div>

                            <Button type="submit" variant="secondary" disabled={identityForm.processing}>
                                Save identity
                            </Button>
                        </div>
                    </form>

                    <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                        <FormRow>
                            <Field label="Provider" htmlFor="provider" required>
                                <SearchSelect id="provider" value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                    {Object.entries(providers).map(([value, name]) => (
                                        <option key={value} value={value}>
                                            {name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>

                            <Field label="Model (optional)" htmlFor="model" size="grow" error={form.errors.model}>
                                {Object.keys(choices).length > 0 ? (
                                    <SearchSelect
                                        id="model"
                                        fit={false}
                                        value={form.data.model}
                                        onChange={(e) => form.setData('model', e.target.value)}
                                    >
                                        <option value="">Provider default</option>
                                        {Object.entries(choices).map(([id, name]) => (
                                            <option key={id} value={id}>
                                                {name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                ) : (
                                    <Input
                                        id="model"
                                        value={form.data.model}
                                        placeholder="Leave empty for the provider default"
                                        onChange={(e) => form.setData('model', e.target.value)}
                                    />
                                )}
                            </Field>
                        </FormRow>

                        <FormRow>
                            <Field label="Label (optional)" htmlFor="label" size="md">
                                <Input
                                    id="label"
                                    value={form.data.label}
                                    placeholder="Main account"
                                    onChange={(e) => form.setData('label', e.target.value)}
                                />
                            </Field>
                            <Field label="API key" htmlFor="secret" size="grow" error={form.errors.secret} required>
                                <Input
                                    id="secret"
                                    type="password"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={form.data.secret}
                                    placeholder="Paste the key here"
                                    onChange={(e) => form.setData('secret', e.target.value)}
                                />
                            </Field>
                        </FormRow>

                        <Button type="submit" disabled={form.processing || form.data.secret.trim() === ''}>
                            Save key
                        </Button>
                    </form>

                    <div className="space-y-3">
                        <h3 className="text-sm font-medium">Saved keys</h3>

                        {credentials.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                No key has been added here yet.
                                {envFallback.anthropic
                                    ? ' The server environment already provides one, so the assistant works; a key added here would take priority over it.'
                                    : ' The assistant stays switched off until one is added.'}
                            </p>
                        )}

                        {credentials.map((c) => (
                            <div key={c.id} className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {providers[c.provider] ?? c.provider}
                                        {c.label ? ` (${c.label})` : ''}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        Ends {c.hint ?? '....'}
                                        {c.created_by ? ` · added by ${c.created_by}` : ''}
                                        {c.updated_at ? ` · ${showDate(c.updated_at)}` : ''}
                                    </p>

                                    {/* The result of the last real call, not a guess from the
                                        shape of the key. A key that has never been tested says
                                        so rather than implying it works. */}
                                    {c.verify_error ? (
                                        <p className="text-destructive mt-1 text-xs">{c.verify_error}</p>
                                    ) : c.verified_at ? (
                                        <p className="mt-1 text-xs text-emerald-700 dark:text-emerald-300">Answered on {showDate(c.verified_at)}</p>
                                    ) : (
                                        <p className="text-muted-foreground mt-1 text-xs">Not tested yet.</p>
                                    )}

                                    {supported[c.provider] === false && (
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            This provider can hold a key, but the assistant cannot send messages through it yet.
                                        </p>
                                    )}

                                    <div className="mt-2 flex items-center gap-2">
                                        <Label htmlFor={`model-${c.id}`} className="text-muted-foreground text-xs">
                                            Model
                                        </Label>
                                        <div className="w-64">
                                            {Object.keys(models[c.provider] ?? {}).length > 0 ? (
                                                <SearchSelect
                                                    id={`model-${c.id}`}
                                                    value={c.model ?? ''}
                                                    onChange={(e) =>
                                                        router.patch(
                                                            `/settings/ai-providers/${c.id}/model`,
                                                            { model: e.target.value },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <option value="">Provider default</option>
                                                    {Object.entries(models[c.provider] ?? {}).map(([id, name]) => (
                                                        <option key={id} value={id}>
                                                            {name}
                                                        </option>
                                                    ))}
                                                    {/* A model saved before it could be picked from a list may no
                                                        longer be offered. It stays visible so the row shows what is
                                                        actually stored rather than silently reading empty. */}
                                                    {c.model && !(models[c.provider] ?? {})[c.model] && (
                                                        <option value={c.model}>{c.model} (not offered by the provider)</option>
                                                    )}
                                                </SearchSelect>
                                            ) : (
                                                <p className="text-muted-foreground text-xs">{c.model ?? 'Provider default'}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <span
                                        className={
                                            c.is_active
                                                ? 'rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                                : 'bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs'
                                        }
                                    >
                                        {c.is_active ? 'In use' : 'Off'}
                                    </span>

                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={testingId === c.id || !c.is_active || supported[c.provider] === false}
                                        onClick={() => {
                                            setTestingId(c.id);
                                            router.post(
                                                `/settings/ai-providers/${c.id}/verify`,
                                                {},
                                                { preserveScroll: true, onFinish: () => setTestingId(null) },
                                            );
                                        }}
                                    >
                                        {testingId === c.id ? 'Testing…' : 'Test'}
                                    </Button>

                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.patch(`/settings/ai-providers/${c.id}/toggle-active`, {}, { preserveScroll: true })}
                                    >
                                        {c.is_active ? 'Switch off' : 'Use this'}
                                    </Button>

                                    {confirmingId === c.id ? (
                                        <>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(`/settings/ai-providers/${c.id}`, {
                                                        preserveScroll: true,
                                                        onFinish: () => setConfirmingId(null),
                                                    })
                                                }
                                            >
                                                Remove
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => setConfirmingId(null)}>
                                                Cancel
                                            </Button>
                                        </>
                                    ) : (
                                        <Button variant="ghost" size="sm" onClick={() => setConfirmingId(c.id)}>
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
