import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Campaign {
    id: number;
    name: string;
    subject: string | null;
    body: string;
    group: string | null;
    group_id: number | null;
    audience_type: string;
    provider: string | null;
    provider_id: number | null;
    cap: number;
    status: string;
    status_label: string;
    editable: boolean;
    awaiting_approval: boolean;
    sent_count: number;
    failed_count: number;
    estimated_cost: string | null;
    sent_at: string | null;
}

interface Preview {
    campaign_id: number;
    count: number;
    parts_each: number;
    parts_total: number;
    characters: number;
    alphabet: string;
    cost: number;
    opted_out: number;
    unreachable: number;
    duplicates: number;
    over_cap: boolean;
    needs_approval: boolean;
}

/**
 * ⚠ Mirrors SmsText on the server so the compose box can show the cost as
 * somebody types. The SERVER's figure is the one that counts: this is a hint,
 * and the preview is the truth.
 */
function measure(body: string): { unicode: boolean; characters: number; parts: number } {
    const gsm = /^[@£$¥èéùìòÇ\n Øø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-./0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\[~\]|€]*$/;
    const unicode = !gsm.test(body);
    const characters = [...body].length;
    const single = unicode ? 70 : 160;
    const concat = unicode ? 67 : 153;
    const parts = characters === 0 ? 0 : characters <= single ? 1 : Math.ceil(characters / concat);

    return { unicode, characters, parts };
}

export default function MessagingIndex({
    channel,
    campaigns,
    groups,
    providers,
    optOuts,
    approvalAbove,
    can,
}: {
    channel: string;
    campaigns: Campaign[];
    groups: { id: number; name: string; contacts_count: number }[];
    providers: { id: number; name: string; is_active: boolean; cost_per_part: string }[];
    optOuts: { id: number; address: string; reason: string | null; at: string | null }[];
    approvalAbove: number;
    can: { send: boolean; approve: boolean };
}) {
    const preview = (usePage().props as unknown as { campaignPreview?: Preview }).campaignPreview;
    const isSms = channel === 'sms';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Phone book', href: '/crm/contacts' },
        { title: isSms ? 'SMS' : 'Email', href: `/crm/messages/${channel}` },
    ];

    const [editing, setEditing] = useState<Campaign | null>(null);

    const form = useForm({
        name: '',
        subject: '',
        body: '',
        audience_type: 'group',
        contact_group_id: '',
        message_provider_id: '',
        recipient_cap: 500,
    });

    const optOutForm = useForm({ address: '', reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('crm.campaigns.update', editing.id), { preserveScroll: true, onSuccess: () => cancel() });

            return;
        }

        form.post(route('crm.messages.store', channel), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const cancel = () => {
        setEditing(null);
        form.clearErrors();
        form.reset();
    };

    const edit = (campaign: Campaign) => {
        setEditing(campaign);
        form.clearErrors();
        form.setData({
            name: campaign.name,
            subject: campaign.subject ?? '',
            body: campaign.body,
            audience_type: campaign.audience_type,
            contact_group_id: campaign.group_id ? String(campaign.group_id) : '',
            message_provider_id: campaign.provider_id ? String(campaign.provider_id) : '',
            recipient_cap: campaign.cap,
        });
    };

    const live = measure(form.data.body);
    const activeProvider = providers.find((p) => String(p.id) === form.data.message_provider_id);
    const liveCost = activeProvider ? live.parts * Number(activeProvider.cost_per_part) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isSms ? 'Send SMS' : 'Send email'} />
            <div className="flex flex-col gap-8 p-4">
                <ReportHeading
                    title={isSms ? 'SMS' : 'Email'}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/crm/messages/${isSms ? 'email' : 'sms'}`}>{isSms ? 'Email instead' : 'SMS instead'}</Link>
                        </Button>
                    }
                />

                {providers.filter((p) => p.is_active).length === 0 && (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950">
                        No active {isSms ? 'SMS' : 'email'} provider is set up, so nothing can be sent yet. Everything else on this page works:
                        drafts, audiences, previews and the do-not-contact list. Add a provider under Settings, Messaging.
                    </div>
                )}

                {can.send && (
                    <form onSubmit={submit} className="flex flex-col gap-4 rounded-lg border p-4">
                        {/* Name grows, the two pickers fit their options (30 Aug 2026
                            audit); the subject and the message take the full width
                            because a sentence needs it. */}
                        <FormRow>
                            <Field label="What this send is called" htmlFor="name" size="grow" error={form.errors.name} required>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Who it goes to" htmlFor="audience_type" required>
                                <SearchSelect
                                    id="audience_type"
                                    value={form.data.audience_type}
                                    onChange={(e) => form.setData('audience_type', e.target.value)}
                                >
                                    <option value="group">One group</option>
                                    <option value="all">Everybody in the phone book</option>
                                </SearchSelect>
                            </Field>
                            {form.data.audience_type === 'group' && (
                                <Field label="Group" htmlFor="contact_group_id" error={form.errors.contact_group_id}>
                                    <SearchSelect
                                        id="contact_group_id"
                                        value={form.data.contact_group_id}
                                        onChange={(e) => form.setData('contact_group_id', e.target.value)}
                                    >
                                        <option value="">Choose a group</option>
                                        {groups.map((g) => (
                                            <option key={g.id} value={g.id}>
                                                {g.name} ({g.contacts_count})
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                            )}
                        </FormRow>
                        {!isSms && (
                            <div className="grid gap-2">
                                <Label htmlFor="subject" required>Subject</Label>
                                <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                                <InputError message={form.errors.subject} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="body" required>
                                Message
                            </Label>
                            <textarea
                                id="body"
                                className="border-input bg-background min-h-28 rounded-md border p-2 text-sm"
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                            />
                            <InputError message={form.errors.body} />
                            {isSms && (
                                <p className="text-muted-foreground text-xs">
                                    {live.characters} characters, {live.parts} part{live.parts === 1 ? '' : 's'} each
                                    {live.unicode && '. Bangla or a special character means 70 per part instead of 160, so this costs more'}
                                    {liveCost !== null && `, about ${liveCost.toFixed(2)} per recipient`}
                                </p>
                            )}
                        </div>
                        <FormRow>
                            <Field label="Send through" htmlFor="message_provider_id">
                                <SearchSelect
                                    id="message_provider_id"
                                    value={form.data.message_provider_id}
                                    onChange={(e) => form.setData('message_provider_id', e.target.value)}
                                >
                                    <option value="">Choose a provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                            {p.is_active ? '' : ' (off)'}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label="Stop above" htmlFor="recipient_cap" size="xs" error={form.errors.recipient_cap} required>
                                <Input
                                    id="recipient_cap"
                                    type="number"
                                    min={1}
                                    value={form.data.recipient_cap}
                                    onChange={(e) => form.setData('recipient_cap', Number(e.target.value))}
                                />
                            </Field>
                            <div className="flex items-center gap-2 self-end">
                                <Button type="submit" disabled={form.processing}>
                                    Save draft
                                </Button>
                                {editing && (
                                    <Button type="button" variant="ghost" onClick={cancel}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </FormRow>
                    </form>
                )}

                {preview && (
                    <div className="flex flex-col gap-2 rounded-lg border p-4">
                        <h3 className="font-medium">What this send would do</h3>
                        <p className="text-sm">
                            <strong>{preview.count}</strong> recipient{preview.count === 1 ? '' : 's'}
                            {preview.parts_each > 0 && (
                                <>
                                    , {preview.parts_each} part{preview.parts_each === 1 ? '' : 's'} each ({preview.parts_total} in total,{' '}
                                    {preview.alphabet === 'unicode' ? 'Unicode, 70 characters per part' : 'GSM, 160 characters per part'})
                                </>
                            )}
                            , costing about <strong>{preview.cost.toFixed(2)}</strong>.
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {preview.opted_out} asked not to be contacted, {preview.unreachable} have no {isSms ? 'phone number' : 'email address'},{' '}
                            {preview.duplicates} would have been a second message to the same {isSms ? 'number' : 'address'}.
                        </p>
                        {preview.over_cap && (
                            <p className="text-destructive text-sm">
                                This is over the cap and will not send. Raise the cap deliberately, or narrow the audience.
                            </p>
                        )}
                        {preview.needs_approval && !preview.over_cap && (
                            <p className="text-sm">
                                Above {approvalAbove} recipients, so pressing send will raise this for approval rather than sending it.
                            </p>
                        )}
                    </div>
                )}

                <section className="flex flex-col gap-3">
                    <h3 className="font-medium">Sends</h3>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Name</th>
                                    <th className="p-3">Audience</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3">Result</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.map((campaign) => (
                                    <tr key={campaign.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{campaign.name}</div>
                                            <div className="text-muted-foreground line-clamp-2 max-w-md text-xs">{campaign.body}</div>
                                        </td>
                                        <td className="p-3">{campaign.audience_type === 'all' ? 'Everybody' : (campaign.group ?? 'No group')}</td>
                                        <td className="p-3">
                                            <Badge variant={campaign.status === 'sent' ? 'secondary' : 'outline'}>{campaign.status_label}</Badge>
                                        </td>
                                        <td className="p-3">
                                            {campaign.sent_at ? (
                                                <>
                                                    {campaign.sent_count} sent
                                                    {campaign.failed_count > 0 && `, ${campaign.failed_count} failed`}
                                                    <div className="text-muted-foreground text-xs">{campaign.sent_at}</div>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {can.send && campaign.editable && (
                                                <>
                                                    <Button variant="ghost" size="sm" onClick={() => edit(campaign)}>
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(route('crm.campaigns.preview', campaign.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Preview
                                                    </Button>
                                                </>
                                            )}
                                            {can.send && campaign.status !== 'sent' && campaign.status !== 'cancelled' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(route('crm.campaigns.send', campaign.id), {}, { preserveScroll: true })
                                                    }
                                                >
                                                    Send
                                                </Button>
                                            )}
                                            {campaign.sent_at && (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={route('crm.campaigns.deliveries', campaign.id)}>Who got it</Link>
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {campaigns.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                            Nothing sent or drafted yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="flex flex-col gap-3">
                    <h3 className="font-medium">Do not contact</h3>

                    {can.send && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                optOutForm.post(route('crm.messages.opt-out', channel), {
                                    preserveScroll: true,
                                    onSuccess: () => optOutForm.reset(),
                                });
                            }}
                            className="flex flex-wrap items-end gap-2 rounded-lg border p-4"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="opt_address" required>{isSms ? 'Number' : 'Email'}</Label>
                                <Input
                                    id="opt_address"
                                    value={optOutForm.data.address}
                                    onChange={(e) => optOutForm.setData('address', e.target.value)}
                                />
                                <InputError message={optOutForm.errors.address} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="opt_reason">Why</Label>
                                <Input
                                    id="opt_reason"
                                    value={optOutForm.data.reason}
                                    onChange={(e) => optOutForm.setData('reason', e.target.value)}
                                />
                            </div>
                            <Button type="submit" disabled={optOutForm.processing}>
                                Add
                            </Button>
                        </form>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{isSms ? 'Number' : 'Address'}</th>
                                    <th className="p-3">Why</th>
                                    <th className="p-3">Since</th>
                                </tr>
                            </thead>
                            <tbody>
                                {optOuts.map((o) => (
                                    <tr key={o.id} className="border-t">
                                        <td className="p-3">{o.address}</td>
                                        <td className="p-3">{o.reason ?? '-'}</td>
                                        <td className="p-3">{o.at}</td>
                                    </tr>
                                ))}
                                {optOuts.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="text-muted-foreground p-6 text-center">
                                            Nobody has asked to be left alone.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
