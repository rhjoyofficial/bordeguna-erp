import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import { type VoucherTypeProps } from './form';
import { StatusBadge } from './index';

export interface VoucherDocument {
    id: number;
    type: VoucherTypeProps;
    title: string;
    number: string | null;
    status: 'draft' | 'posted' | 'reversed';
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
    reversal: { id: number; number: string; date: string; reason: string | null; by: string | null } | null;
    copied_from: { id: number; number: string | null } | null;
    copies: { id: number; title: string; status: string }[];
    prepared_by: string | null;
    posted_by: string | null;
    posted_at: string | null;
    attachments: { id: number; name: string; url: string }[];
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
    const fileInput = useRef<HTMLInputElement>(null);
    const type = voucher.type;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/day-book' },
        { title: type.plural, href: route('accounting.vouchers.index', type.value) },
        { title: voucher.title, href: '#' },
    ];

    const reverse = (reason: string, copy: boolean) =>
        router.post(route('accounting.vouchers.reverse', voucher.id), { reason, copy }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={voucher.title} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="font-mono text-lg font-semibold">{voucher.title}</h1>
                            <StatusBadge status={voucher.status} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {type.label} · {voucher.date}
                            {voucher.prepared_by ? ` · prepared by ${voucher.prepared_by}` : ''}
                            {voucher.posted_by ? ` · posted by ${voucher.posted_by}, ${voucher.posted_at}` : ''}
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
                        {voucher.status !== 'draft' && (
                            <Button asChild variant="outline">
                                <Link href={route('accounting.vouchers.print', voucher.id)}>Print</Link>
                            </Button>
                        )}
                        {voucher.status !== 'draft' && canPrepareVouchers && (
                            <Button variant="outline" onClick={() => router.post(route('accounting.vouchers.duplicate', voucher.id))}>
                                Duplicate
                            </Button>
                        )}
                        {voucher.status === 'posted' && canPostVouchers && (
                            <>
                                <ConfirmDialog
                                    trigger={<Button variant="outline">Reverse and copy</Button>}
                                    title="Reverse this voucher and correct it?"
                                    description="A posted voucher cannot be edited. This cancels it with a reversing entry and opens a new draft with the same details, ready to be corrected and posted."
                                    confirmLabel="Reverse and copy"
                                    withNote
                                    noteRequired
                                    noteLabel="What was wrong with it?"
                                    notePlaceholder="e.g. Amount should have been 26,000"
                                    onConfirm={(reason) => reverse(reason, true)}
                                />
                                <ConfirmDialog
                                    trigger={<Button variant="destructive">Reverse</Button>}
                                    title="Reverse this voucher?"
                                    description="The voucher and its number stay on record, marked Reversed, and a reversing entry dated today cancels its effect on the books."
                                    confirmLabel="Reverse"
                                    destructive
                                    withNote
                                    noteRequired
                                    noteLabel="Why is it being reversed?"
                                    onConfirm={(reason) => reverse(reason, false)}
                                />
                            </>
                        )}
                    </div>
                </div>

                {voucher.reversal && (
                    <div className="rounded-md border border-red-500/40 bg-red-500/5 px-4 py-3 text-sm">
                        Reversed on {voucher.reversal.date} by{' '}
                        <Link href={route('accounting.journals.show', voucher.reversal.id)} className="font-mono underline">
                            {voucher.reversal.number}
                        </Link>
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
                            <dd>{voucher.money_account ?? '—'}</dd>
                        </div>
                    )}
                    {type.value === 'contra' && (
                        <div>
                            <dt className="text-muted-foreground">Moved to</dt>
                            <dd>{voucher.to_money_account ?? '—'}</dd>
                        </div>
                    )}
                    {type.counterparty_label && (
                        <div>
                            <dt className="text-muted-foreground">{type.counterparty_label}</dt>
                            <dd>{voucher.counterparty ?? '—'}</dd>
                        </div>
                    )}
                    {voucher.instrument.type && (
                        <div>
                            <dt className="text-muted-foreground">Paid by</dt>
                            <dd>
                                {INSTRUMENT_LABEL[voucher.instrument.type] ?? voucher.instrument.type}
                                {voucher.instrument.number ? ` no. ${voucher.instrument.number}` : ''}
                                {voucher.instrument.date ? `, dated ${voucher.instrument.date}` : ''}
                                {voucher.instrument.bank ? `, ${voucher.instrument.bank}` : ''}
                            </dd>
                        </div>
                    )}
                    {voucher.journal && (
                        <div>
                            <dt className="text-muted-foreground">Ledger entry</dt>
                            <dd>
                                <Link href={route('accounting.journals.show', voucher.journal.id)} className="font-mono underline">
                                    {voucher.journal.number}
                                </Link>
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
                                            {line.account}
                                            {line.party && <span className="text-muted-foreground"> · {line.party}</span>}
                                        </td>
                                        <td className="text-muted-foreground py-2 pr-3">{line.narration}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{line.debit}</td>
                                        <td className="py-2 text-right tabular-nums">{line.credit}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="text-sm">
                    <div className="text-base font-semibold tabular-nums">Total {voucher.total}</div>
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
                                {canPrepareVouchers && (
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
