import { BusyButton } from '@/components/busy-button';
import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import SelfServiceTabs from '@/components/self-service-tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Category = { id: number; name: string; name_bn: string | null; receipt_required: boolean };

type Line = {
    id: number;
    spent_on: string | null;
    amount: string;
    description: string | null;
    category: string | null;
    category_id: number | null;
    is_declined: boolean;
    declined_reason: string | null;
    receipts: { id: number; name: string; url: string }[];
};

type Claim = {
    id: number;
    claim_number: string;
    spent_on: string | null;
    amount: string;
    category: string | null;
    purpose: string;
    state: string;
    is_draft: boolean;
    was_sent_back: boolean;
    sent_back_note: string | null;
    is_settled: boolean;
    settlement_method: string | null;
    settled_by: string | null;
    lines: Line[];
    receipts: { id: number; name: string; url: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My expenses', href: '/hrm/my-expenses' }];

const money = (value: string | number) =>
    Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The whole-claim refusal, which belongs to no single box on the form: no
 * spends on it yet, or one of them still missing its receipt. Read loosely
 * because the server names it `claim`, which is not one of the form's fields.
 */
const claimError = (errors: Record<string, string>) => errors.claim;

/**
 * What I spent on the company, and whether I have been paid back.
 *
 * ⚠⚠ ONE CLAIM CAN HOLD SEVERAL SPENDS (Joy, 9 Sep 2026). A market trip is
 * goods, a rickshaw, a courier and tea for the supplier. Under one claim per
 * spend that was four claims, four approvals and four payments for a single
 * afternoon, so the claim became a draft you build and send once.
 *
 * ⚠⚠ AND A SINGLE COURIER IS STILL ONE PRESS. The form has two buttons: "Send
 * for approval" does the whole thing at once, "Add and keep open" leaves the
 * claim waiting for the next spend. Making everybody start a claim, add a spend
 * and then submit would have fixed the trip by taxing the common case.
 *
 * ⚠ The receipt is part of the form, not a step afterwards. A spend with no
 * proof cannot be approved by anybody honestly, so it cannot be submitted
 * either, unless the accountant marked the category as one that never has a
 * receipt (rickshaw fare, tea).
 */
export default function MyExpenseClaims({
    categories = [],
    drafts = [],
    claims,
    today,
}: {
    categories: Category[];
    drafts: Claim[];
    claims: Claim[];
    today: string;
}) {
    const { locale } = useTranslation();

    // The category list is data, not interface text, so it cannot come from the
    // compile-time dictionary: a category the accountant adds next month needs a
    // Bangla label too. English is the fallback when the column is blank.
    const label = (category: Category) => (locale === 'bn' && category.name_bn ? category.name_bn : category.name);

    const form = useForm<{
        action: 'add' | 'submit';
        claim_id: string;
        purpose: string;
        spent_on: string;
        amount: string;
        expense_category_id: string;
        description: string;
        receipt: File | null;
    }>({
        action: 'submit',
        claim_id: '',
        purpose: '',
        spent_on: today,
        amount: '',
        expense_category_id: '',
        description: '',
        receipt: null,
    });

    const chosen = categories.find((category) => String(category.id) === form.data.expense_category_id);
    const receiptNeeded = chosen ? chosen.receipt_required : true;
    const startingNew = form.data.claim_id === '';

    const send = (action: 'add' | 'submit') => {
        form.transform((data) => ({ ...data, action }));
        form.post('/hrm/my-expenses', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: (page) => {
                // ⚠⚠ THE SECOND SPEND HAS TO LAND ON THE SAME CLAIM. Adding a
                // spend to a NEW claim leaves this form still saying "start a
                // new claim", so pressing Add again would quietly open a second
                // one and the trip would be split after all. The newest draft
                // is the claim just created, and the list comes back newest
                // first.
                const drafts = (page.props.drafts ?? []) as Claim[];

                form.setData({
                    ...form.data,
                    claim_id: action === 'add' && drafts.length > 0 ? String(drafts[0].id) : '',
                    purpose: '',
                    amount: '',
                    expense_category_id: '',
                    description: '',
                    receipt: null,
                });
            },
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send('submit');
    };

    const outstanding = claims.filter((claim) => !claim.is_settled).reduce((sum, claim) => sum + Number(claim.amount), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My expenses" />

            <div className="space-y-6 p-4">
                <SelfServiceTabs active="/hrm/my-expenses" />

                <HeadingSmall
                    title="Money I spent for the office"
                    description="Claim back what you paid out of your own pocket. Attach the receipt and it goes to your manager. Several spends on the same trip can go on one claim."
                />

                {outstanding > 0 && (
                    <p className="text-sm">
                        Waiting to be paid back: <span className="font-medium tabular-nums">BDT {money(outstanding)}</span>
                    </p>
                )}

                {/* ⚠⚠ ONE ROW, Joy's own layout (10 Sep 2026): what the claim is
                    for / when / how much / category / receipt, all across, and the
                    spend's own note underneath. He asked for the title to join the
                    other four rather than sit on a line of its own above them.

                    ⚠ The five SHARE the row (flex-1 with a min-width each) rather
                    than sitting in equal grid columns: a grid would hand the date
                    the same fifth as the receipt, and the row would not reflow on a
                    phone. Each min-width is what that control actually holds, and
                    none is wider than a phone, so the row becomes a column at ~400px
                    instead of scrolling sideways.

                    ⚠ `Which claim` is a MODE SWITCH, not one of the five fields, so
                    it stays on its own line and only when there is a draft to
                    choose. Folding it into the row would make the row's meaning
                    change shape depending on how many drafts happen to be open. */}
                <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                    {drafts.length > 0 && (
                        <FormRow>
                            <Field label="Which claim" htmlFor="claim_id" size="lg" error={form.errors.claim_id}>
                                <SearchSelect
                                    id="claim_id"
                                    value={form.data.claim_id}
                                    onChange={(e) => form.setData('claim_id', e.target.value)}
                                >
                                    <option value="">Start a new claim</option>
                                    {drafts.map((draft) => (
                                        <option key={draft.id} value={String(draft.id)}>
                                            {draft.claim_number}: {draft.purpose}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        </FormRow>
                    )}

                    <FormRow>
                        {/* ⚠ Only when starting one. Adding a spend to a draft that
                            already has a title must not offer to retype it. */}
                        {startingNew && (
                            <Field
                                label="What is this claim for"
                                htmlFor="purpose"
                                className="min-w-52 max-w-md flex-[2]"
                                error={form.errors.purpose}
                            >
                                <Input
                                    id="purpose"
                                    value={form.data.purpose}
                                    placeholder="Dhaka market trip"
                                    onChange={(e) => form.setData('purpose', e.target.value)}
                                />
                            </Field>
                        )}

                        <Field label="When you spent it" htmlFor="spent_on" className="min-w-36" error={form.errors.spent_on}>
                            <Input
                                id="spent_on"
                                type="date"
                                max={today}
                                value={form.data.spent_on}
                                onChange={(e) => form.setData('spent_on', e.target.value)}
                            />
                        </Field>

                        <Field label="How much (BDT)" htmlFor="amount" className="min-w-28 max-w-40 flex-[1]" error={form.errors.amount}>
                            <Input
                                id="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                className="w-full text-right tabular-nums"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="Expense category"
                            htmlFor="expense_category_id"
                            className="min-w-44 max-w-sm flex-[2]"
                            error={form.errors.expense_category_id}
                        >
                            <SearchSelect
                                id="expense_category_id"
                                value={form.data.expense_category_id}
                                onChange={(e) => form.setData('expense_category_id', e.target.value)}
                            >
                                <option value="">Choose the category…</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={String(category.id)}>
                                        {label(category)}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>

                        <Field
                            label={receiptNeeded ? 'Receipt' : 'Receipt (not needed)'}
                            htmlFor="receipt"
                            className="min-w-48 max-w-sm flex-[2]"
                            error={form.errors.receipt}
                        >
                            <Input
                                id="receipt"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="What this spend was for" htmlFor="description" size="grow" error={form.errors.description}>
                            <Input
                                id="description"
                                value={form.data.description}
                                placeholder="Courier to Savar for the Rahman order"
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    {claimError(form.errors) && <p className="text-destructive text-sm">{claimError(form.errors)}</p>}

                    <div className="flex flex-wrap gap-2">
                        <Button disabled={form.processing}>Send for approval</Button>
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => send('add')}>
                            Add and keep the claim open
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Use the second button when the same trip had more than one spend. Nobody sees the claim until you send it.
                    </p>
                </form>

                {drafts.map((draft) => (
                    <DraftClaim key={draft.id} draft={draft} />
                ))}

                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Claim</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">What for</th>
                                <th className="p-3">Spends</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            {claims.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-3">
                                        You have not sent any claim yet.
                                    </td>
                                </tr>
                            )}
                            {claims.map((claim) => (
                                <tr key={claim.id} className="border-border border-t align-top">
                                    <td className="p-3 font-medium">{claim.claim_number}</td>
                                    <td className="p-3">{claim.spent_on ?? '-'}</td>
                                    <td className="p-3">{claim.purpose}</td>
                                    <td className="p-3">
                                        <ul className="space-y-1">
                                            {claim.lines.map((line) => (
                                                <li key={line.id} className={line.is_declined ? 'text-muted-foreground' : ''}>
                                                    <span className="tabular-nums">{money(line.amount)}</span>{' '}
                                                    {line.category ?? 'Uncategorised'}
                                                    {line.description ? `: ${line.description}` : ''}
                                                    {line.receipts.map((file) => (
                                                        <a
                                                            key={file.id}
                                                            href={file.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="ml-1 text-xs underline"
                                                        >
                                                            receipt
                                                        </a>
                                                    ))}
                                                    {line.is_declined && (
                                                        <span className="block text-xs text-amber-700">
                                                            Declined: {line.declined_reason}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{money(claim.amount)}</td>
                                    <td className="p-3">
                                        {claim.state}
                                        {claim.settled_by && <span className="text-muted-foreground block text-xs">by {claim.settled_by}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * A claim still in the employee's hands: one they are building, or one an
 * approver handed back.
 *
 * ⚠ The send-back note is the first thing on the card, in the approver's own
 * words. Being told "sent back" without being told what to change leaves
 * somebody looking for a person to ask, which is the state this feature exists
 * to end.
 */
function DraftClaim({ draft }: { draft: Claim }) {
    const remove = (lineId: number) =>
        router.delete(`/hrm/my-expenses/${draft.id}/lines/${lineId}`, { preserveScroll: true });

    return (
        <div className="border-border space-y-3 rounded-md border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {draft.claim_number}: {draft.purpose}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {draft.was_sent_back ? 'Sent back for correction' : 'Not sent yet. Nobody can see it.'}
                    </p>
                </div>
                <p className="tabular-nums">
                    Total <span className="font-medium">BDT {money(draft.amount)}</span>
                </p>
            </div>

            {draft.was_sent_back && draft.sent_back_note && (
                <p className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-800">
                    What needs correcting: {draft.sent_back_note}
                </p>
            )}

            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-2">Date</th>
                            <th className="p-2">Category</th>
                            <th className="p-2">What for</th>
                            <th className="p-2 text-right">Amount</th>
                            <th className="p-2">Receipt</th>
                            <th className="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {draft.lines.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-muted-foreground p-2">
                                    No spends on this claim yet.
                                </td>
                            </tr>
                        )}
                        {draft.lines.map((line) => (
                            <tr key={line.id} className="border-border border-t">
                                <td className="p-2">{line.spent_on ?? '-'}</td>
                                <td className="p-2">{line.category ?? '-'}</td>
                                <td className="p-2">{line.description ?? '-'}</td>
                                <td className="p-2 text-right tabular-nums">{money(line.amount)}</td>
                                <td className="p-2">
                                    {line.receipts.length === 0 ? (
                                        <span className="text-muted-foreground text-xs">none</span>
                                    ) : (
                                        line.receipts.map((file) => (
                                            <a
                                                key={file.id}
                                                href={file.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="block text-xs underline"
                                            >
                                                {file.name}
                                            </a>
                                        ))
                                    )}
                                </td>
                                <td className="p-2 text-right">
                                    <button type="button" className="text-destructive text-xs underline" onClick={() => remove(line.id)}>
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap gap-2">
                <BusyButton
                    size="sm"
                    busyLabel="Sending…"
                    disabled={draft.lines.length === 0}
                    onClick={() => router.post(`/hrm/my-expenses/${draft.id}/submit`, {}, { preserveScroll: true })}
                >
                    Send for approval
                </BusyButton>
                <BusyButton
                    type="button"
                    size="sm"
                    variant="outline"
                    busyLabel="Discarding…"
                    onClick={() => router.delete(`/hrm/my-expenses/${draft.id}`, { preserveScroll: true })}
                >
                    Discard this claim
                </BusyButton>
            </div>
        </div>
    );
}
