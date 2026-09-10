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

/** The whole-claim refusal, which belongs to no single box on the form. */
const claimError = (errors: Record<string, string>) => errors.claim;

/**
 * What I spent on the company, and whether I have been paid back.
 *
 * ⚠⚠ ONE SPEND IS THE WHOLE SCREEN (Joy, 10 Sep 2026: "it works, I did not like
 * the process"). The version before this asked for a claim TITLE as well as a
 * spend note - the same sentence twice for the single courier that is most of
 * the traffic - and made the employee choose between two equally loud buttons.
 * It solved the market trip by taxing the thing that happens every day.
 *
 * So: five fields and one button. Add the spend. It lands on your open claim, or
 * starts one. Sending it is a separate, quieter press on the claim itself, and
 * a second spend is the same five fields again.
 *
 * ⚠ There is exactly ONE open claim at a time, which is why no "which claim"
 * picker exists any more. A claim sent back for correction occupies that same
 * slot, because it is the thing you should be dealing with.
 *
 * ⚠ The receipt stays mandatory unless the ACCOUNTANT marked the category as one
 * that never has a receipt. That decision belongs to them and not to the person
 * spending the money, who has every reason to say they lost it.
 */
export default function MyExpenseClaims({
    categories = [],
    draft = null,
    claims,
    today,
}: {
    categories: Category[];
    draft: Claim | null;
    claims: Claim[];
    today: string;
}) {
    const { locale } = useTranslation();

    // The category list is data, not interface text, so it cannot come from the
    // compile-time dictionary: a category the accountant adds next month needs a
    // Bangla label too. English is the fallback when the column is blank.
    const label = (category: Category) => (locale === 'bn' && category.name_bn ? category.name_bn : category.name);

    const form = useForm<{
        spent_on: string;
        amount: string;
        expense_category_id: string;
        description: string;
        receipt: File | null;
    }>({
        spent_on: today,
        amount: '',
        expense_category_id: '',
        description: '',
        receipt: null,
    });

    const chosen = categories.find((category) => String(category.id) === form.data.expense_category_id);
    const receiptNeeded = chosen ? chosen.receipt_required : true;

    // ⚠⚠ THE BUTTON REFUSES BEFORE THE SERVER HAS TO (Joy, 10 Sep 2026: "in many
    // area if click button without filling the fields shows errors"). Pressing a
    // blank form and being handed a wall of red is the server telling you what
    // the screen already knew. The server still validates - this only stops the
    // round trip that exists to say "you left it empty".
    const missing =
        form.data.amount.trim() === '' ||
        Number(form.data.amount) <= 0 ||
        form.data.expense_category_id === '' ||
        form.data.spent_on === '' ||
        (receiptNeeded && form.data.receipt === null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        form.post('/hrm/my-expenses', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset('amount', 'expense_category_id', 'description', 'receipt'),
        });
    };

    const outstanding = claims.filter((claim) => !claim.is_settled).reduce((sum, claim) => sum + Number(claim.amount), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My expenses" />

            <div className="space-y-6 p-4">
                <SelfServiceTabs active="/hrm/my-expenses" />

                <HeadingSmall
                    title="Money I spent for the office"
                    description="Add what you paid out of your own pocket, attach the receipt, and send it to your manager. Spend twice on the same trip? Add it twice, then send once."
                />

                {outstanding > 0 && (
                    <p className="text-sm">
                        Waiting to be paid back: <span className="font-medium tabular-nums">BDT {money(outstanding)}</span>
                    </p>
                )}

                {/* ⚠ One row of five, sized to what each control holds rather than
                    to a fifth of the column, with a ceiling as well as a floor so
                    no field balloons when the row wraps. Measured from 1400px down
                    to 360px: it becomes a column and never scrolls sideways. */}
                <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                    <FormRow>
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
                            label="What kind of expense"
                            htmlFor="expense_category_id"
                            className="min-w-44 max-w-sm flex-[2]"
                            error={form.errors.expense_category_id}
                        >
                            <SearchSelect
                                id="expense_category_id"
                                value={form.data.expense_category_id}
                                onChange={(e) => form.setData('expense_category_id', e.target.value)}
                            >
                                <option value="">Choose…</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={String(category.id)}>
                                        {label(category)}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>

                        <Field label="When" htmlFor="spent_on" className="min-w-36" error={form.errors.spent_on}>
                            <Input
                                id="spent_on"
                                type="date"
                                max={today}
                                value={form.data.spent_on}
                                onChange={(e) => form.setData('spent_on', e.target.value)}
                            />
                        </Field>

                        <Field label="What it was for" htmlFor="description" className="min-w-52 max-w-md flex-[2]" error={form.errors.description}>
                            <Input
                                id="description"
                                value={form.data.description}
                                placeholder="Courier to Savar for the Rahman order"
                                onChange={(e) => form.setData('description', e.target.value)}
                            />
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

                    {claimError(form.errors) && <p className="text-destructive text-sm">{claimError(form.errors)}</p>}

                    <Button disabled={form.processing || missing}>Add expense</Button>
                </form>

                {draft && <OpenClaim draft={draft} />}

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
                                        <Spends lines={claim.lines} />
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

/** The spends on a claim, as the employee reads them back. */
function Spends({ lines }: { lines: Line[] }) {
    return (
        <ul className="space-y-1">
            {lines.map((line) => (
                <li key={line.id} className={line.is_declined ? 'text-muted-foreground' : ''}>
                    <span className="tabular-nums">{money(line.amount)}</span> {line.category ?? 'Uncategorised'}
                    {line.description ? `: ${line.description}` : ''}
                    {line.receipts.map((file) => (
                        <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="ml-1 text-xs underline">
                            receipt
                        </a>
                    ))}
                    {line.is_declined && <span className="block text-xs text-amber-700">Declined: {line.declined_reason}</span>}
                </li>
            ))}
        </ul>
    );
}

/**
 * The claim being built, or one handed back for correction.
 *
 * ⚠ "Send for approval" is the only loud thing here, because it is the only
 * thing that leaves the employee's hands. Adding another spend happens in the
 * form above and needs no button of its own down here.
 *
 * ⚠ The send-back note comes first, in the approver's own words. Being told
 * "sent back" without being told what to change leaves somebody hunting for a
 * person to ask, which is the state this feature exists to end.
 */
function OpenClaim({ draft }: { draft: Claim }) {
    return (
        <div className="border-border space-y-3 rounded-md border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {draft.claim_number}
                        {draft.spent_on ? ` · ${draft.spent_on}` : ''} · {draft.lines.length}{' '}
                        {draft.lines.length === 1 ? 'spend' : 'spends'}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {draft.was_sent_back ? 'Sent back for correction' : 'Not sent yet. Nobody else can see it.'}
                    </p>
                </div>
                <p className="tabular-nums">
                    Total <span className="font-medium">BDT {money(draft.amount)}</span>
                </p>
            </div>

            {draft.was_sent_back && draft.sent_back_note && (
                <p className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-800">What needs correcting: {draft.sent_back_note}</p>
            )}

            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-2">When</th>
                            <th className="p-2">What kind</th>
                            <th className="p-2">What it was for</th>
                            <th className="p-2 text-right">Amount</th>
                            <th className="p-2">Receipt</th>
                            <th className="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
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
                                            <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="block text-xs underline">
                                                {file.name}
                                            </a>
                                        ))
                                    )}
                                </td>
                                <td className="p-2 text-right">
                                    <button
                                        type="button"
                                        className="text-destructive text-xs underline"
                                        onClick={() => router.delete(`/hrm/my-expenses/${draft.id}/lines/${line.id}`, { preserveScroll: true })}
                                    >
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
                    busyLabel="Sending…"
                    disabled={draft.lines.length === 0}
                    onClick={() => router.post(`/hrm/my-expenses/${draft.id}/submit`, {}, { preserveScroll: true })}
                >
                    Send for approval
                </BusyButton>
                <BusyButton
                    type="button"
                    size="sm"
                    variant="ghost"
                    busyLabel="Discarding…"
                    onClick={() => router.delete(`/hrm/my-expenses/${draft.id}`, { preserveScroll: true })}
                >
                    Discard
                </BusyButton>
            </div>

            <p className="text-muted-foreground text-xs">
                Once you send it you cannot change it. If you remember another spend afterwards, add it and it becomes a new claim.
            </p>
        </div>
    );
}
