import { CorrectionButtons, CorrectionChainLabel, type AccountsCorrections } from '@/components/accounting/correction-buttons';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Amount, DateCell, DocNo, LedgerHead, PartyText } from '@/components/ledger-text';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { type VoucherTypeProps } from './form';
import { StatusBadge } from './index';

export interface VoucherDocument {
    id: number;
    type: VoucherTypeProps;
    title: string;
    number: string | null;
    status: 'draft' | 'pending_approval' | 'posted' | 'reversed';
    can_approve?: boolean;
    date: string;
    counterparty: string | null;
    narration: string | null;
    money_account: string | null;
    to_money_account: string | null;
    instrument: { type: string | null; number: string | null; date: string | null; bank: string | null };
    total: string;
    total_in_words: string;
    lines: { account: string | null; party: string | null; narration: string | null; debit: string; credit: string }[];
    journal: { id: number; number: string } | null;
    settles?: { party: string; amount: string; against: string[] }[];
    reversal: { id: number; number: string; date: string; reason: string | null; by: string | null } | null;
    copied_from: { id: number; number: string | null } | null;
    copies: { id: number; title: string; status: string }[];
    prepared_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    attachments: { id: number; name: string; url: string }[];
    /** ⭐ Corrections wave 2 (24 Oct 2026): the buttons, the chain, Fix details and Withdraw. */
    corrections?:
        | (AccountsCorrections & {
              withdrawal_reasons: ReasonOption[];
              approval_request_id: number | null;
              fix: {
                  seen: string;
                  narration: string | null;
                  counterparty: string | null;
                  counterparty_is_party: boolean;
                  instrument_bank: string | null;
                  lines: { id: number; line_no: number; account: string | null; narration: string | null }[];
              };
          })
        | null;
}

/**
 * ⭐ Corrections wave 2 (24 Oct 2026): Fix details, text only, with a reason.
 * The date-free fields of a posted voucher; no journal moves.
 */
function FixDetailsDialog({ voucher }: { voucher: VoucherDocument }) {
    const { t } = useTranslation();
    const fix = voucher.corrections!.fix;
    const [narration, setNarration] = useState(fix.narration ?? '');
    const [counterparty, setCounterparty] = useState(fix.counterparty ?? '');
    const [bank, setBank] = useState(fix.instrument_bank ?? '');
    const [lines, setLines] = useState<Record<number, string>>(Object.fromEntries(fix.lines.map((l) => [l.id, l.narration ?? ''])));

    return (
        <CorrectionReasonDialog
            trigger={
                <Button type="button" variant="outline">
                    {t('corr.acc.fix_details')}
                </Button>
            }
            title={t('corr.acc.fix_title', { number: voucher.title })}
            description={t('corr.acc.fix_help')}
            reasons={voucher.corrections!.reasons.fix_details}
            submitUrl={route('accounting.vouchers.fix-details', voucher.id)}
            confirmLabel={t('corr.acc.save')}
            data={() => ({
                narration,
                ...(fix.counterparty_is_party ? {} : { counterparty }),
                ...(voucher.instrument.type === 'cheque' ? { instrument_bank: bank } : {}),
                lines,
                seen: fix.seen,
            })}
        >
            <div className="grid gap-2">
                <Label htmlFor="fix-narration">{t('corr.acc.narration')}</Label>
                <textarea
                    id="fix-narration"
                    value={narration}
                    onChange={(e) => setNarration(e.target.value)}
                    className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                />
            </div>
            {voucher.type.counterparty_label && (
                <div className="grid gap-2">
                    <Label htmlFor="fix-counterparty">{t('corr.acc.paid_to')}</Label>
                    <Input
                        id="fix-counterparty"
                        className="w-72 max-w-full"
                        value={counterparty}
                        disabled={fix.counterparty_is_party}
                        onChange={(e) => setCounterparty(e.target.value)}
                    />
                    {fix.counterparty_is_party && <p className="text-muted-foreground text-xs">{t('corr.acc.party_fixed')}</p>}
                </div>
            )}
            {voucher.instrument.type === 'cheque' && (
                <div className="grid gap-2">
                    <Label htmlFor="fix-bank">{t('corr.acc.cheque_bank')}</Label>
                    <Input id="fix-bank" className="w-72 max-w-full" value={bank} onChange={(e) => setBank(e.target.value)} />
                </div>
            )}
            {fix.lines.map((line) => (
                <div key={line.id} className="grid gap-2">
                    <Label htmlFor={`fix-line-${line.id}`}>{t('corr.acc.line_text', { line: line.line_no, account: line.account ?? '' })}</Label>
                    <Input
                        id={`fix-line-${line.id}`}
                        className="w-96 max-w-full"
                        value={lines[line.id] ?? ''}
                        onChange={(e) => setLines((previous) => ({ ...previous, [line.id]: e.target.value }))}
                    />
                </div>
            ))}
        </CorrectionReasonDialog>
    );
}

export const INSTRUMENT_LABEL: Record<string, string> = {
    cash: 'Cash',
    cheque: 'Cheque',
    bank_transfer: 'Bank transfer',
    mobile: 'bKash / Nagad / Rocket',
    card: 'Card',
    other: 'Other',
};

export const moneyLabelFor = (type: VoucherTypeProps['value']) =>
    type === 'payment' ? 'Paid from' : type === 'receipt' ? 'Received into' : 'Moved from';

export default function VoucherShow({ voucher }: { voucher: VoucherDocument }) {
    const { canPrepareVouchers, canPostVouchers } = usePage<SharedData>().props;
    const { t } = useTranslation();
    const corrections = voucher.corrections ?? null;
    const fixDetails = corrections?.actions.find((a) => a.action === 'fix_details');
    const withdraw = corrections?.actions.find((a) => a.action === 'withdraw');
    const fileInput = useRef<HTMLInputElement>(null);
    const type = voucher.type;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/day-book' },
        { title: type.plural, href: route('accounting.vouchers.index', type.value) },
        { title: voucher.title, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={voucher.title} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-primary font-mono text-lg font-semibold">{voucher.title}</h1>
                            <StatusBadge status={voucher.status} />
                            <CorrectionChainLabel corrections={corrections} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {type.label} · <DateCell>{showDate(voucher.date)}</DateCell>
                            {voucher.prepared_by ? ` · prepared by ${voucher.prepared_by}` : ''}
                            {voucher.posted_by ? ` · posted by ${voucher.posted_by}, ${showDate(voucher.posted_at)}` : ''}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {voucher.status === 'draft' && canPrepareVouchers && (
                            <>
                                <Button asChild variant="outline">
                                    <Link href={route('accounting.vouchers.edit', voucher.id)}>Edit</Link>
                                </Button>
                                <ConfirmDialog
                                    trigger={<Button variant="outline">Delete draft</Button>}
                                    title="Delete this draft?"
                                    description="A draft has not touched the books. Deleting it leaves no trace and uses no voucher number."
                                    confirmLabel="Delete draft"
                                    destructive
                                    onConfirm={() => router.delete(route('accounting.vouchers.destroy', voucher.id))}
                                />
                            </>
                        )}
                        {voucher.status === 'draft' && canPostVouchers && (
                            <ConfirmDialog
                                trigger={<Button>Post</Button>}
                                title={`Post this ${type.label.toLowerCase()}?`}
                                description={`It gets its ${type.prefix} number and is written to the ledger for ${voucher.total}. After this it cannot be edited or deleted, only reversed.`}
                                confirmLabel="Post"
                                onConfirm={() => router.post(route('accounting.vouchers.post', voucher.id), {}, { preserveScroll: true })}
                            />
                        )}
                        {voucher.status === 'pending_approval' && voucher.can_approve && (
                            <>
                                <ConfirmDialog
                                    trigger={<Button variant="outline">Send back</Button>}
                                    title="Send it back to its preparer?"
                                    description="It becomes a draft again, to be corrected and sent again."
                                    confirmLabel="Send back"
                                    withNote
                                    noteRequired
                                    noteLabel="What needs changing?"
                                    onConfirm={(note) =>
                                        router.post(route('accounting.vouchers.send-back', voucher.id), { note }, { preserveScroll: true })
                                    }
                                />
                                <ConfirmDialog
                                    trigger={<Button>Approve and post</Button>}
                                    title={`Approve this ${type.label.toLowerCase()}?`}
                                    description={`It is posted at once for ${voucher.total}, with its ${type.prefix} number.`}
                                    confirmLabel="Approve and post"
                                    onConfirm={() => router.post(route('accounting.vouchers.approve', voucher.id), {}, { preserveScroll: true })}
                                />
                            </>
                        )}
                        {(voucher.status === 'posted' || voucher.status === 'reversed') && (
                            <Button asChild variant="outline">
                                <Link href={route('accounting.vouchers.print', voucher.id)}>Print</Link>
                            </Button>
                        )}
                        {(voucher.status === 'posted' || voucher.status === 'reversed') && canPrepareVouchers && (
                            <Button variant="outline" onClick={() => router.post(route('accounting.vouchers.duplicate', voucher.id))}>
                                {/* A reversed voucher's Copy keeps its date and cheque (Reverse, then Copy). */}
                                {voucher.status === 'reversed' ? t('corr.acc.copy') : 'Duplicate'}
                            </Button>
                        )}
                        {/* ⭐ Corrections wave 2 (24 Oct 2026): the policy's buttons. Withdraw
                            while it waits (its sender); Fix details, Correct and Reverse on a
                            posted voucher, each greyed with its reason when blocked. */}
                        {withdraw && corrections?.approval_request_id && withdraw.allowed && (
                            <CorrectionReasonDialog
                                trigger={<Button variant="outline">{t('corr.acc.withdraw')}</Button>}
                                title={t('corr.acc.withdraw_title', { number: voucher.title })}
                                description={t('corr.acc.withdraw_help')}
                                reasons={corrections.withdrawal_reasons}
                                submitUrl={route('approvals.withdraw', corrections.approval_request_id)}
                                confirmLabel={t('corr.acc.withdraw')}
                            />
                        )}
                        {fixDetails?.allowed && <FixDetailsDialog voucher={voucher} />}
                        <CorrectionButtons
                            corrections={corrections}
                            recordId={voucher.id}
                            recordLabel={voucher.title}
                            correctUrl={route('accounting.vouchers.correct', voucher.id)}
                        />
                    </div>
                </div>

                {voucher.status === 'pending_approval' && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                        Above the approval limit: waiting for a second person to approve it. Nothing is in the books until then.
                    </div>
                )}

                {voucher.reversal && (
                    <div className="rounded-md border border-red-500/40 bg-red-500/5 px-4 py-3 text-sm">
                        Reversed on <DateCell>{showDate(voucher.reversal.date)}</DateCell> by{' '}
                        <DocNo href={route('accounting.journals.show', voucher.reversal.id)}>{voucher.reversal.number}</DocNo>
                        {voucher.reversal.by ? ` (${voucher.reversal.by})` : ''}. Reason: {voucher.reversal.reason}
                    </div>
                )}

                {(voucher.copied_from || voucher.copies.length > 0) && (
                    <p className="text-muted-foreground text-sm">
                        {voucher.copied_from && (
                            <>
                                Copied from{' '}
                                <Link href={route('accounting.vouchers.show', voucher.copied_from.id)} className="font-mono underline">
                                    {voucher.copied_from.number ?? 'a draft'}
                                </Link>
                                .{' '}
                            </>
                        )}
                        {voucher.copies.length > 0 && (
                            <>
                                Copied into{' '}
                                {voucher.copies.map((copy, index) => (
                                    <span key={copy.id}>
                                        {index > 0 && ', '}
                                        <Link href={route('accounting.vouchers.show', copy.id)} className="font-mono underline">
                                            {copy.title}
                                        </Link>
                                    </span>
                                ))}
                                .
                            </>
                        )}
                    </p>
                )}

                <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    {type.uses_money_account && (
                        <div>
                            <dt className="text-muted-foreground">{moneyLabelFor(type.value)}</dt>
                            <dd>
                                <LedgerHead name={voucher.money_account ?? '-'} />
                            </dd>
                        </div>
                    )}
                    {type.value === 'contra' && (
                        <div>
                            <dt className="text-muted-foreground">Moved to</dt>
                            <dd>
                                <LedgerHead name={voucher.to_money_account ?? '-'} />
                            </dd>
                        </div>
                    )}
                    {type.counterparty_label && (
                        <div>
                            <dt className="text-muted-foreground">{type.counterparty_label}</dt>
                            <dd>{voucher.counterparty ? <PartyText>{voucher.counterparty}</PartyText> : '-'}</dd>
                        </div>
                    )}
                    {voucher.instrument.type && (
                        <div>
                            <dt className="text-muted-foreground">Paid by</dt>
                            <dd>
                                {INSTRUMENT_LABEL[voucher.instrument.type] ?? voucher.instrument.type}
                                {voucher.instrument.number ? ` no. ${voucher.instrument.number}` : ''}
                                {voucher.instrument.date ? `, dated ${showDate(voucher.instrument.date)}` : ''}
                                {voucher.instrument.bank ? `, ${voucher.instrument.bank}` : ''}
                            </dd>
                        </div>
                    )}
                    {voucher.journal && (
                        <div>
                            <dt className="text-muted-foreground">Ledger entry</dt>
                            <dd>
                                <DocNo href={route('accounting.journals.show', voucher.journal.id)}>{voucher.journal.number}</DocNo>
                            </dd>
                        </div>
                    )}
                </dl>

                {type.has_lines && (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                    <th className="py-2 pr-3">Particulars</th>
                                    <th className="py-2 pr-3">Narration</th>
                                    <th className="py-2 pr-3 text-right">Debit</th>
                                    <th className="py-2 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {voucher.lines.map((line, index) => (
                                    <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                        <td className="py-2 pr-3">
                                            <LedgerHead label={line.account} />
                                            {line.party && (
                                                <>
                                                    <span className="text-muted-foreground"> · </span>
                                                    <PartyText>{line.party}</PartyText>
                                                </>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 pr-3">{line.narration}</td>
                                        <td className="py-2 pr-3 text-right">
                                            <Amount value={line.debit} tone="debit" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={line.credit} tone="credit" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {voucher.settles && voucher.settles.length > 0 && (
                    <div className="text-sm">
                        <h2 className="mb-1 font-medium">Settled</h2>
                        <ul className="flex flex-col gap-1">
                            {voucher.settles.map((settle, index) => (
                                <li key={index}>
                                    <PartyText>{settle.party}</PartyText>: <Amount value={settle.amount} />
                                    <span className="text-muted-foreground"> ({settle.against.join(', ')})</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="text-sm">
                    <div className="text-base font-bold">
                        Total <Amount value={voucher.total} tone="total" className="text-base" />
                    </div>
                    <div className="text-muted-foreground">{voucher.total_in_words}</div>
                    {voucher.narration && <p className="mt-2">Narration: {voucher.narration}</p>}
                </div>

                <div className="text-sm">
                    <h2 className="mb-2 font-medium">Attachments</h2>
                    {voucher.attachments.length === 0 && <p className="text-muted-foreground">No bill, receipt or cheque copy attached.</p>}
                    <ul className="flex flex-col gap-1">
                        {voucher.attachments.map((file) => (
                            <li key={file.id} className="flex items-center gap-3">
                                <a href={file.url} target="_blank" rel="noreferrer" className="underline">
                                    {file.name}
                                </a>
                                {/* ACC-008 (14 Oct 2026): proof on a voucher past draft is removed
                                    only by whoever may post, and the server logs it. */}
                                {(voucher.status === 'draft' ? canPrepareVouchers : canPostVouchers) && (
                                    <ConfirmDialog
                                        trigger={<button className="text-muted-foreground text-xs underline">Remove</button>}
                                        title="Remove this attachment?"
                                        confirmLabel="Remove"
                                        destructive
                                        onConfirm={() =>
                                            router.delete(route('accounting.vouchers.evidence.destroy', [voucher.id, file.id]), {
                                                preserveScroll: true,
                                            })
                                        }
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                    {canPrepareVouchers && (
                        <>
                            <input
                                ref={fileInput}
                                type="file"
                                className="hidden"
                                accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];

                                    if (file) {
                                        router.post(
                                            route('accounting.vouchers.evidence.store', voucher.id),
                                            { file },
                                            { forceFormData: true, preserveScroll: true, onFinish: () => (e.target.value = '') },
                                        );
                                    }
                                }}
                            />
                            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => fileInput.current?.click()}>
                                Attach a file
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
