import InputError from '@/components/input-error';
import { Amount, DateCell, DocNo, PartyText } from '@/components/ledger-text';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Row {
    id: number;
    party: string | null;
    number: string;
    date: string | null;
    due: string | null;
    amount: string;
    outstanding: string;
    removable: boolean;
    /** ⭐ B13 (24 Oct 2026): Reverse keeps the document, marked; nothing is deleted. */
    reversal?: { on: string | null; by: string | null; reason: string | null } | null;
    /** The month it is dated in is closed: the reversal goes on today. */
    closed?: boolean;
}

interface Option {
    id: number;
    label: string;
}

/** ⭐ Supplier advances (ruling P2, 18 Oct 2026): what a supplier held of ours at cutover. */
type Kind = 'receivable' | 'payable' | 'advance';

interface Props {
    invoices: Row[];
    bills: Row[];
    advances?: Row[];
    totals: { receivable: string; payable: string; advances?: string };
    customers: Option[];
    suppliers: Option[];
    reasons?: ReasonOption[];
}

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 3.1 "Party adjustment, opening receivable or
 * payable: Reverse with reason and date choice. The document is kept"): a
 * reason, and its own date or today.
 */
function ReverseOpening({ row, kind, reasons }: { row: Row; kind: Kind; reasons: ReasonOption[] }) {
    const { t } = useTranslation();
    const [when, setWhen] = useState<'original' | 'today'>(row.closed ? 'today' : 'original');

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline">
                    {t('corr.acc.reverse')}
                </Button>
            }
            title={t('corr.fu.opening_reverse_title', { number: row.number })}
            description={t('corr.fu.opening_reverse_help')}
            reasons={reasons}
            submitUrl={route('accounting.opening-balances.reverse', [kind, row.id])}
            confirmLabel={t('corr.acc.reverse')}
            destructive
            data={() => ({ when })}
        >
            <fieldset className="grid gap-1 text-sm">
                <legend className="mb-1 font-medium">{t('corr.fu.reverse_on')}</legend>
                <label className="flex items-center gap-2">
                    <input type="radio" name="when" checked={when === 'original'} disabled={row.closed} onChange={() => setWhen('original')} />
                    {t('corr.fu.its_own_date', { date: row.date ? showDate(row.date) : '' })}
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" name="when" checked={when === 'today'} onChange={() => setWhen('today')} />
                    {t('corr.fu.today')}
                </label>
                {row.closed && <span className="text-muted-foreground text-xs">{t('corr.fu.month_closed_today')}</span>}
            </fieldset>
        </CorrectionReasonDialog>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Opening balances', href: '/accounting/opening-balances' }];

function Table({ title, total, rows, kind, reasons }: { title: string; total: string; rows: Row[]; kind: Kind; reasons: ReasonOption[] }) {
    const { t } = useTranslation();

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
            <div className="mb-2 flex items-baseline justify-between gap-4">
                <h2 className="font-semibold">{title}</h2>
                <span className="text-sm font-bold">
                    Total <Amount value={total} tone="total" />
                </span>
            </div>
            {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">None entered.</p>
            ) : (
                <table className="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left">
                            <th className="py-2 pr-3">{kind === 'receivable' ? 'Customer' : 'Supplier'}</th>
                            <th className="py-2 pr-3">Number</th>
                            <th className="py-2 pr-3">Date</th>
                            <th className="py-2 pr-3">Due</th>
                            <th className="py-2 pr-3 text-right">Amount</th>
                            <th className="py-2 pr-3 text-right">Still owed</th>
                            <th className="py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id} className={`border-b align-top ${row.reversal ? 'text-muted-foreground' : ''}`}>
                                <td className="py-2 pr-3">
                                    <PartyText>{row.party}</PartyText>
                                    {row.reversal && (
                                        <div className="text-xs">
                                            {t('corr.acc.reversed_on', {
                                                date: row.reversal.on ? showDate(row.reversal.on) : '',
                                                by: row.reversal.by ?? '',
                                                reason: row.reversal.reason ?? '',
                                            })}
                                        </div>
                                    )}
                                </td>
                                <td className="py-2 pr-3">
                                    <DocNo>{row.number}</DocNo>
                                </td>
                                <td className="py-2 pr-3">{row.date ? <DateCell>{showDate(row.date)}</DateCell> : null}</td>
                                <td className="py-2 pr-3">{row.due ? <DateCell>{row.due}</DateCell> : null}</td>
                                <td className="py-2 pr-3 text-right">
                                    <Amount value={row.amount} tone={kind === 'payable' ? 'credit' : 'debit'} />
                                </td>
                                <td className="py-2 pr-3 text-right">
                                    <Amount value={row.outstanding} tone="balance" />
                                </td>
                                <td className="py-2 text-right">
                                    {row.reversal ? (
                                        <span className="text-xs">{t('corr.acc.reversed')}</span>
                                    ) : (
                                        row.removable && <ReverseOpening row={row} kind={kind} reasons={reasons} />
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

export default function OpeningBalances({ invoices, bills, advances = [], totals, customers, suppliers, reasons = [] }: Props) {
    const form = useForm<{ kind: Kind; party_id: string; reference: string; date: string; due_date: string; amount: string }>({
        kind: 'receivable',
        party_id: '',
        reference: '',
        date: '',
        due_date: '',
        amount: '',
    });
    const parties = form.data.kind === 'receivable' ? customers : suppliers;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('accounting.opening-balances.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('party_id', 'reference', 'date', 'due_date', 'amount'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opening balances" />

            <div className="flex flex-col gap-6 p-4">
                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="flex flex-wrap gap-4 text-sm">
                        {(['receivable', 'payable', 'advance'] as const).map((kind) => (
                            <label key={kind} className="flex cursor-pointer items-center gap-2">
                                <input
                                    type="radio"
                                    name="kind"
                                    checked={form.data.kind === kind}
                                    onChange={() => form.setData({ ...form.data, kind, party_id: '' })}
                                />
                                {kind === 'receivable'
                                    ? 'A customer owes us'
                                    : kind === 'payable'
                                      ? 'We owe a supplier'
                                      : 'A supplier holds our advance'}
                            </label>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-start gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="date" required>
                                Date
                            </Label>
                            <Input
                                id="date"
                                type="date"
                                className="w-auto"
                                value={form.data.date}
                                onChange={(e) => form.setData('date', e.target.value)}
                            />
                            <InputError message={form.errors.date} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="party_id" required>
                                {form.data.kind === 'receivable' ? 'Customer' : 'Supplier'}
                            </Label>
                            <SearchSelect fit id="party_id" value={form.data.party_id} onChange={(e) => form.setData('party_id', e.target.value)}>
                                <option value="">Choose</option>
                                {parties.map((party) => (
                                    <option key={party.id} value={party.id}>
                                        {party.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={form.errors.party_id} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="reference" required>
                                {form.data.kind === 'receivable' ? 'Old invoice no.' : form.data.kind === 'payable' ? 'Bill no.' : 'Reference'}
                            </Label>
                            <Input
                                id="reference"
                                className="w-40"
                                value={form.data.reference}
                                onChange={(e) => form.setData('reference', e.target.value)}
                            />
                            <InputError message={form.errors.reference} />
                        </div>
                        <div className={form.data.kind === 'advance' ? 'hidden' : 'flex flex-col gap-1'}>
                            <Label htmlFor="due_date">Due date</Label>
                            <Input
                                id="due_date"
                                type="date"
                                className="w-auto"
                                value={form.data.due_date}
                                onChange={(e) => form.setData('due_date', e.target.value)}
                            />
                            <InputError message={form.errors.due_date} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="amount" required>
                                Still owed
                            </Label>
                            <Input
                                id="amount"
                                type="number"
                                min="0"
                                step="0.01"
                                className="w-36"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                            <InputError message={form.errors.amount} />
                        </div>
                    </div>
                    <InputError message={(form.errors as Record<string, string>).document} />

                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>

                <Table title="Customers owed us" total={totals.receivable} rows={invoices} kind="receivable" reasons={reasons} />
                <Table title="We owed suppliers" total={totals.payable} rows={bills} kind="payable" reasons={reasons} />
                <Table title="Advances suppliers held (Dr 1160)" total={totals.advances ?? '0.00'} rows={advances} kind="advance" reasons={reasons} />
            </div>
        </AppLayout>
    );
}
