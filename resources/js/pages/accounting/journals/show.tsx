import { CorrectionButtons, CorrectionChainLabel, type AccountsCorrections } from '@/components/accounting/correction-buttons';
import InputError from '@/components/input-error';
import { Amount, DateCell, LedgerHead, StatusPill } from '@/components/ledger-text';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface Line {
    line_no: number;
    account: string;
    /** The employee, customer or supplier a party-kept head's line names. */
    party: string | null;
    memo: string | null;
    debit: string | null;
    credit: string | null;
}

interface Journal {
    id: number;
    number: string;
    date: string;
    type_label: string;
    memo: string | null;
    posted_by: string | null;
    posted_at: string | null;
    is_reversal: boolean;
    reverses: string | null;
    reversed: boolean;
    /** Made by another screen (a voucher, payroll, stock): corrected there, never reversed here. */
    has_source: boolean;
    total: string;
    lines: Line[];
    /** ⭐ Corrections wave 2 (24 Oct 2026): Correct and Reverse with a reason, for a manual journal. */
    corrections: AccountsCorrections | null;
}

export default function ShowJournal({ journal }: { journal: Journal }) {
    const { canPostJournal, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: 'Journal register', href: '/accounting/journals' },
        { title: journal.number, href: `/accounting/journals/${journal.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={journal.number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-primary font-mono text-lg font-semibold">{journal.number}</h1>
                            <Badge variant="outline">{journal.type_label}</Badge>
                            {journal.is_reversal && <Badge variant="secondary">Reverses {journal.reverses}</Badge>}
                            {journal.reversed && <StatusPill status="reversed" label="Reversed" />}
                            <CorrectionChainLabel corrections={journal.corrections} />
                        </div>
                        {journal.memo && <p className="text-sm">{journal.memo}</p>}
                        <p className="text-muted-foreground text-sm">
                            Dated <DateCell>{showDate(journal.date)}</DateCell>
                            {journal.posted_by && ` · posted by ${journal.posted_by}`}
                            {journal.posted_at && ` on ${showDate(journal.posted_at)}`}
                        </p>
                    </div>

                    {/* ACC-004 (14 Oct 2026): a reversal posts at once and cannot be
                        undone, so it asks first; and a journal another screen made is
                        corrected on that screen, so it gets a sentence, not a button. */}
                    {/* ⭐ Corrections wave 2 (24 Oct 2026): Correct (the record form
                        pre-filled) and Reverse (a reason, and its own date or Today),
                        greyed with the reason when blocked. */}
                    {canPostJournal && !journal.reversed && !journal.is_reversal && !journal.has_source && (
                        <CorrectionButtons
                            corrections={journal.corrections}
                            recordId={journal.id}
                            recordLabel={journal.number}
                            correctUrl={route('accounting.journals.correct', journal.id)}
                        />
                    )}
                    {canPostJournal && !journal.reversed && !journal.is_reversal && journal.has_source && (
                        <p className="text-muted-foreground max-w-sm text-sm">{t('books.journal.from_source')}</p>
                    )}
                </div>

                <InputError message={errors?.lines} className="text-sm" />

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="w-10 py-2">#</th>
                                <th className="py-2">Account</th>
                                <th className="py-2">Note</th>
                                <th className="py-2 text-right">Debit</th>
                                <th className="py-2 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {journal.lines.map((line) => (
                                <tr key={line.line_no} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="text-muted-foreground py-2 tabular-nums">{line.line_no}</td>
                                    <td className="py-2">
                                        <LedgerHead label={line.account} />
                                        {line.party && <div className="text-muted-foreground text-xs">{line.party}</div>}
                                    </td>
                                    <td className="text-muted-foreground py-2">{line.memo}</td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.debit} tone="debit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={line.credit} tone="credit" />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-bold">
                                <td className="py-2" colSpan={3}>
                                    Total
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={journal.total} tone="debit" className="font-bold" />
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={journal.total} tone="credit" className="font-bold" />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
