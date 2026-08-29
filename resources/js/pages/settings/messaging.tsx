import InputError from '@/components/input-error';
import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

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
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Messaging', href: '/crm/settings/messaging' }];

const EMPTY = {
    name: '',
    channel: 'sms',
    driver: '',
    sender_id: '',
    priority: 100,
    cost_per_part: '0',
    credentials: { api_key: '', api_secret: '' } as Record<string, string>,
};

export default function MessagingSettings({
    providers,
    drivers,
    mailer,
}: {
    providers: Provider[];
    drivers: { value: string; label: string; channel: string }[];
    mailer: string;
}) {
    const [editing, setEditing] = useState<Provider | null>(null);
    const form = useForm(EMPTY);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('crm.settings.messaging.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => cancel(),
            });

            return;
        }

        form.post(route('crm.settings.messaging.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const cancel = () => {
        setEditing(null);
        form.clearErrors();
        form.reset();
    };

    const edit = (provider: Provider) => {
        setEditing(provider);
        form.clearErrors();
        form.setData({
            name: provider.name,
            channel: provider.channel,
            driver: provider.driver,
            sender_id: provider.sender_id ?? '',
            priority: provider.priority,
            cost_per_part: provider.cost_per_part,
            // ⚠ Always blank. The server never sends a secret back, and a blank
            // field means "leave it alone" rather than "erase it".
            credentials: { api_key: '', api_secret: '' },
        });
    };

    const forChannel = drivers.filter((d) => d.channel === form.data.channel);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Messaging providers" />

            <SettingsLayout wide>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Messaging"
                        description="How the business sends SMS and email. Add as many providers as you like, and switch them on and off."
                    />

                    {mailer === 'log' && (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950">
                            The application mailer is set to <strong>log</strong>, so no email actually leaves the server. Email campaigns will be
                            accepted and written to the log until the MAIL_ settings are filled in on the server.
                        </div>
                    )}

                    <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="channel">Sends</Label>
                            <SearchSelect
                                id="channel"
                                value={form.data.channel}
                                onChange={(e) => form.setData({ ...form.data, channel: e.target.value, driver: '' })}
                            >
                                <option value="sms">SMS</option>
                                <option value="email">Email</option>
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="driver">Through</Label>
                            <SearchSelect id="driver" value={form.data.driver} onChange={(e) => form.setData('driver', e.target.value)}>
                                <option value="">Choose</option>
                                {forChannel.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={form.errors.driver} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sender_id">{form.data.channel === 'sms' ? 'Sender ID (masking)' : 'From address'}</Label>
                            <Input id="sender_id" value={form.data.sender_id} onChange={(e) => form.setData('sender_id', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cost_per_part">Cost per part</Label>
                            <Input
                                id="cost_per_part"
                                inputMode="decimal"
                                value={form.data.cost_per_part}
                                onChange={(e) => form.setData('cost_per_part', e.target.value)}
                            />
                            <InputError message={form.errors.cost_per_part} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="priority">Priority</Label>
                            <Input
                                id="priority"
                                type="number"
                                min={1}
                                value={form.data.priority}
                                onChange={(e) => form.setData('priority', Number(e.target.value))}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="api_key">API key</Label>
                            <Input
                                id="api_key"
                                type="password"
                                autoComplete="off"
                                value={form.data.credentials.api_key}
                                onChange={(e) => form.setData('credentials', { ...form.data.credentials, api_key: e.target.value })}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="api_secret">API secret</Label>
                            <Input
                                id="api_secret"
                                type="password"
                                autoComplete="off"
                                value={form.data.credentials.api_secret}
                                onChange={(e) => form.setData('credentials', { ...form.data.credentials, api_secret: e.target.value })}
                            />
                        </div>
                        <p className="text-muted-foreground md:col-span-3 text-xs">
                            Keys are stored encrypted and are never shown again, only their last four characters. Leaving them blank when editing
                            keeps whatever is already saved.
                        </p>
                        <div className="flex gap-2 md:col-span-3">
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
                                    <tr key={provider.id} className="border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{provider.name}</div>
                                            {provider.sender_id && <div className="text-muted-foreground text-xs">{provider.sender_id}</div>}
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
                                            <Badge variant={provider.is_active ? 'secondary' : 'outline'}>
                                                {provider.is_active ? 'On' : 'Off'}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            <Button variant="ghost" size="sm" onClick={() => edit(provider)}>
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        route('crm.settings.messaging.toggle', provider.id),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
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
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
