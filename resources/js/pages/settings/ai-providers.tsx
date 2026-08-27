import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ShieldCheck, Trash2 } from 'lucide-react';
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
    envFallback: Record<string, boolean>;
    flash?: { success?: string };
};


export default function AiProviders() {
    const { credentials, providers, envFallback } = usePage<PageProps>().props;
    const [confirmingId, setConfirmingId] = useState<number | null>(null);

    const form = useForm({
        provider: 'anthropic',
        label: '',
        model: '',
        secret: '',
    });

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
                <div className="space-y-6">
                    <HeadingSmall title="AI providers" description="Add an API key so the ERP guide and future AI features can work." />

                    <div className="border-border bg-muted/40 flex items-start gap-3 rounded-md border p-4 text-sm">
                        <ShieldCheck className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        <p className="text-muted-foreground">
                            A key is encrypted before it is stored and is never sent back to the browser, written to a log, or shown again. Only the
                            last four characters stay readable so you can tell two keys apart. To change a key, add the new one; it replaces the old
                            without showing it.
                        </p>
                    </div>

                    <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="provider">Provider</Label>
                                <SearchSelect id="provider" value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                    {Object.entries(providers).map(([value, name]) => (
                                        <option key={value} value={value}>
                                            {name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="model">Model (optional)</Label>
                                <Input
                                    id="model"
                                    value={form.data.model}
                                    placeholder="claude-opus-5"
                                    onChange={(e) => form.setData('model', e.target.value)}
                                />
                                {form.errors.model && <p className="text-sm text-red-600">{form.errors.model}</p>}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="label">Label (optional)</Label>
                            <Input
                                id="label"
                                value={form.data.label}
                                placeholder="Main account"
                                onChange={(e) => form.setData('label', e.target.value)}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="secret">API key</Label>
                            <Input
                                id="secret"
                                type="password"
                                autoComplete="off"
                                spellCheck={false}
                                value={form.data.secret}
                                placeholder="Paste the key here"
                                onChange={(e) => form.setData('secret', e.target.value)}
                            />
                            {form.errors.secret && <p className="text-sm text-red-600">{form.errors.secret}</p>}
                        </div>

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
                                        {c.model ? ` · ${c.model}` : ''}
                                        {c.created_by ? ` · added by ${c.created_by}` : ''}
                                        {c.updated_at ? ` · ${c.updated_at}` : ''}
                                    </p>
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
