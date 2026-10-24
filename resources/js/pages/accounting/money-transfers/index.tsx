import { CorrectionButtons, CorrectionChainLabel, type AccountsCorrections } from '@/components/accounting/correction-buttons';
import { ActionDialog } from '@/components/action-dialog';
import { Pagination } from '@/components/data-table/pagination';
import { Amount, DateCell, DocNo, LedgerHead } from '@/components/ledger-text';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Transfer {
    id: number;
    transfer_number: string;
    transferred_on: string | null;
    from: string | null;
    to: string | null;
    amount: string;
    fee: string;
    total_withdrawn: string;
    fee_account: string | null;
    reference: string | null;
    note: string | null;
    from_money_account_id: number;
    to_money_account_id: number;
    fee_account_id: number | null;
    reversal: { on: string | null; by: string | null; reason: string | null; journal: string | null } | null;
    allow_today: boolean;
    corrections: AccountsCorrections;
}

type Option = { id: number; name?: string; label?: string };

/**
 * ⭐ Corrections wave 2 (24 Oct 2026): a transfer's Correct, in a popup. The
 * date comes first; nothing is saved from here: "Preview and correct" opens
 * the action dialog, which previews and posts through the corrections process.
 */
function CorrectTransferDialog({
    transfer,
    accounts,
    feeAccounts,
    onClose,
}: {
    transfer: Transfer;
    accounts: Option[];
    feeAccounts: Option[];
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        transferred_on: transfer.transferred_on ?? '',
        from_money_account_id: String(transfer.from_money_account_id),
        to_money_account_id: String(transfer.to_money_account_id),
        amount: transfer.amount,
        fee: transfer.fee,
        fee_account_id: transfer.fee_account_id ? String(transfer.fee_account_id) : '',
        reference: transfer.reference ?? '',
        note: transfer.note ?? '',
    });
    const set = (key: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [key]: value }));
    const select = 'border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('corr.acc.correct_title', { number: transfer.transfer_number })}</DialogTitle>
                    <DialogDescription>
                        {t('corr.acc.banner', { number: transfer.transfer_number, amount: transfer.total_withdrawn })}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <div className="grid gap-1">
                        <Label htmlFor="trf-date" required>
                            {t('corr.acc.date')}
                        </Label>
                        <Input
                            id="trf-date"
                            type="date"
                            className="w-fit"
                            value={form.transferred_on}
                            onChange={(e) => set('transferred_on', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trf-from">{t('corr.acc.from')}</Label>
                        <select
                            id="trf-from"
                            className={select}
                            value={form.from_money_account_id}
                            onChange={(e) => set('from_money_account_id', e.target.value)}
                        >
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trf-to">{t('corr.acc.to')}</Label>
                        <select
                            id="trf-to"
                            className={select}
                            value={form.to_money_account_id}
                            onChange={(e) => set('to_money_account_id', e.target.value)}
                        >
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="trf-amount">{t('corr.acc.amount')}</Label>
                            <Input
                                id="trf-amount"
                                inputMode="decimal"
                                className="w-36"
                                value={form.amount}
                                onChange={(e) => set('amount', e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="trf-fee">{t('corr.acc.fee')}</Label>
                            <Input id="trf-fee" inputMode="decimal" className="w-28" value={form.fee} onChange={(e) => set('fee', e.target.value)} />
                        </div>
                    </div>
                    {Number(form.fee) > 0 && (
                        <div className="grid gap-1">
                            <Label htmlFor="trf-fee-head">{t('corr.acc.fee_head')}</Label>
                            <select
                                id="trf-fee-head"
                                className={select}
                                value={form.fee_account_id}
                                onChange={(e) => set('fee_account_id', e.target.value)}
                            >
                                <option value="">{t('corr.acc.choose')}</option>
                                {feeAccounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="grid gap-1">
                        <Label htmlFor="trf-ref">{t('corr.acc.reference')}</Label>
                        <Input id="trf-ref" className="w-60" value={form.reference} onChange={(e) => set('reference', e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trf-note">{t('corr.acc.note')}</Label>
                        <textarea
                            id="trf-note"
                            className="border-input bg-background min-h-14 w-full rounded-md border px-3 py-2 text-sm"
                            value={form.note}
                            onChange={(e) => set('note', e.target.value)}
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        {t('corr.acc.go_back')}
                    </Button>
                    <ActionDialog
                        trigger={<Button type="button">{t('corr.acc.preview_and_correct')}</Button>}
                        action="correct"
                        recordLabel={transfer.transfer_number}
                        previewUrl={`/records/${transfer.corrections.type}/${transfer.id}/correct/preview`}
                        submitUrl={`/records/${transfer.corrections.type}/${transfer.id}/correct`}
                        reasons={transfer.corrections.reasons.correction}
                        replacement={form}
                    />
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/money-transfers' },
    { title: 'Money transfers', href: '/accounting/money-transfers' },
];

/**
 * The TRF- transfers recorded before 26 Sep 2026, read-only.
 *
 * A move between the business's own accounts is now a Contra voucher: drafted,
 * numbered CV- at posting, printed, reversed with a reason, and picked by
 * ledger head (every cash, bank and wallet account owns one). The old form is
 * gone so there is one way to do it; the history stays because a transfer made
 * in August must still be readable in December.
 */
export default function MoneyTransfersIndex({
    transfers,
    accounts = [],
    fee_accounts = [],
    showCorrections = false,
}: {
    transfers: Paginated<Transfer>;
    accounts?: Option[];
    fee_accounts?: Option[];
    showCorrections?: boolean;
}) {
    const { canPrepareVouchers } = usePage<SharedData>().props;
    const { t } = useTranslation();
    // The rows name each transfer `t`; inside them the translator is `tr`.
    const tr = t;
    const [correcting, setCorrecting] = useState<Transfer | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Money transfers" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Money transfers" />

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed p-4 text-sm">
                    <span>
                        Move money between accounts with a <strong>Contra voucher</strong>.
                    </span>
                    {canPrepareVouchers ? (
                        <Button asChild>
                            <Link href="/accounting/vouchers/contra/create">New contra voucher</Link>
                        </Button>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href="/accounting/vouchers/contra">Contra vouchers</Link>
                        </Button>
                    )}
                </div>

                <label className="flex w-fit items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={showCorrections}
                        onChange={(e) =>
                            router.get(route('accounting.money-transfers.index'), e.target.checked ? { show_corrections: 1 } : {}, {
                                preserveState: true,
                                preserveScroll: true,
                            })
                        }
                    />
                    {t('corr.acc.show_corrections')}
                </label>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Transfer</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">From</th>
                                <th className="p-3">To</th>
                                <th className="p-3 text-right">Arrived</th>
                                <th className="p-3 text-right">Fee</th>
                                <th className="p-3 text-right">Left the source</th>
                                <th className="p-3">Reference</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground p-3" colSpan={9}>
                                        No transfers were recorded on this screen. Newer moves are under Contra vouchers.
                                    </td>
                                </tr>
                            )}
                            {transfers.data.map((t) => (
                                <tr key={t.id} id={`transfer-${t.id}`} className={`border-t ${t.reversal ? 'text-muted-foreground' : ''}`}>
                                    <td className="p-3">
                                        <DocNo>{t.transfer_number}</DocNo>
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {t.reversal && !t.corrections.corrected_into && (
                                                <span className="rounded bg-zinc-200 px-2 py-0.5 text-xs dark:bg-zinc-800">
                                                    {tr('corr.acc.reversed')}
                                                </span>
                                            )}
                                            <CorrectionChainLabel corrections={t.corrections} />
                                        </div>
                                    </td>
                                    <td className="p-3">{t.transferred_on ? <DateCell>{showDate(t.transferred_on)}</DateCell> : '-'}</td>
                                    <td className="p-3">
                                        <LedgerHead name={t.from ?? '-'} />
                                    </td>
                                    <td className="p-3">
                                        <LedgerHead name={t.to ?? '-'} />
                                    </td>
                                    <td className="p-3 text-right">
                                        <Amount value={t.amount} tone="debit" />
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {Number(t.fee) > 0 ? t.fee : '-'}
                                        {t.fee_account && <div className="text-muted-foreground text-xs">{t.fee_account}</div>}
                                    </td>
                                    <td className="p-3 text-right">
                                        <Amount value={t.total_withdrawn} tone="credit" />
                                    </td>
                                    <td className="text-muted-foreground p-3">{t.reference ?? '-'}</td>
                                    <td className="p-3">
                                        {!t.reversal && (
                                            <CorrectionButtons
                                                corrections={{ ...t.corrections, allow_today: t.allow_today }}
                                                recordId={t.id}
                                                recordLabel={t.transfer_number}
                                                onCorrect={() => setCorrecting(t)}
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={transfers} />
                {correcting && (
                    <CorrectTransferDialog transfer={correcting} accounts={accounts} feeAccounts={fee_accounts} onClose={() => setCorrecting(null)} />
                )}
            </div>
        </AppLayout>
    );
}
