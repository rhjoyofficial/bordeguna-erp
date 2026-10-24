import InputError from '@/components/input-error';
import { Amount, DateCell, DocNo } from '@/components/ledger-text';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * Bank statements (Module 2, Slice 3): the bank's claim on the left, the
 * book's unclaimed lines on the right. Auto-matching already did the timid,
 * certain part; this screen is where the human settles the rest - match,
 * unmatch, or ignore a charge not yet booked.
 */
interface StatementLine {
    id: number;
    txn_date: string;
    description: string | null;
    reference: string | null;
    amount: string | number;
    match_status: string;
    matched_journal_line_id: number | null;
}

interface BookLine {
    id: number;
    date: string;
    journal: string;
    memo: string | null;
    amount: number;
}

/** One list's place in its pages (FEA-007): each list pages on its own. */
interface Pages {
    page: number;
    last: number;
    total: number;
}

interface BankStatementsProps {
    accounts: { id: number; name: string; type: string; is_active: boolean }[];
    money_account_id: number;
    statement_lines: StatementLine[];
    statement_pages: Pages;
    unmatched_book_lines: BookLine[];
    book_pages: Pages;
    /** Every unclaimed book line whose amount equals an unmatched statement line on this page. */
    match_candidates: BookLine[];
    can_act: boolean;
    /** ⭐ Corrections wave 2 (24 Oct 2026): this account's imports, removable while nothing in one is matched. */
    imports?: { id: number; filename: string; lines: number; matched: number; at: string }[];
    can_remove?: boolean;
    removeReasons?: ReasonOption[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bank statements', href: '/accounting/bank-statements' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

/** Money into the bank reads as a debit to it, money out as a credit: the registers' shared colours. */
const flow = (value: number | string) => (Number(value) < 0 ? 'credit' : 'debit');

/**
 * Newer / older for one list (FEA-007, 14 Oct 2026): the lists stopped at 200
 * lines with nothing to say there were more.
 */
function PageSwitch({ pages, param }: { pages: Pages; param: 'page' | 'book_page' }) {
    const { t } = useTranslation();

    if (pages.last <= 1) {
        return null;
    }

    const go = (page: number) => {
        const query = Object.fromEntries(new URLSearchParams(window.location.search));
        router.get('/accounting/bank-statements', { ...query, [param]: page }, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <Button variant="outline" size="sm" disabled={pages.page <= 1} onClick={() => go(pages.page - 1)}>
                {t('books.bank.newer')}
            </Button>
            <span className="text-muted-foreground">{t('books.bank.page_of', { page: pages.page, last: pages.last })}</span>
            <Button variant="outline" size="sm" disabled={pages.page >= pages.last} onClick={() => go(pages.page + 1)}>
                {t('books.bank.older')}
            </Button>
        </div>
    );
}

export default function BankStatements({
    accounts,
    money_account_id,
    statement_lines,
    statement_pages,
    unmatched_book_lines,
    book_pages,
    match_candidates,
    can_act,
    imports = [],
    can_remove = false,
    removeReasons = [],
}: BankStatementsProps) {
    const { t } = useTranslation();
    const importForm = useForm<{ money_account_id: number; file: File | null }>({ money_account_id, file: null });
    const [selecting, setSelecting] = useState<number | null>(null);

    const submitImport: FormEventHandler = (e) => {
        e.preventDefault();
        importForm.post('/accounting/bank-statements/import', { preserveScroll: true, forceFormData: true });
    };

    const act = (line: number, action: string, payload: Record<string, string | number> = {}) =>
        router.post(`/accounting/bank-statements/${line}/${action}`, payload, { preserveScroll: true });

    const statusBadge = (status: string) =>
        status === 'matched' ? 'text-green-700' : status === 'ignored' ? 'text-muted-foreground line-through' : 'text-destructive font-medium';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank statements" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">Import the bank's statement, then match each line to a voucher.</p>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={money_account_id}
                        onChange={(e) => router.get('/accounting/bank-statements', { money_account_id: e.target.value })}
                    >
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.is_active ? account.name : t('books.bank.account_off', { name: account.name })}
                            </option>
                        ))}
                    </select>
                </div>

                {can_act && (
                    <form onSubmit={submitImport} className="border-border bg-card flex flex-wrap items-center gap-3 rounded-lg border p-3">
                        <Label htmlFor="statement_file" required>
                            Statement file
                        </Label>
                        <input
                            id="statement_file"
                            type="file"
                            required
                            accept=".csv,.txt"
                            className="text-sm"
                            onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)}
                        />
                        <Button type="submit" size="sm" disabled={importForm.processing || !importForm.data.file}>
                            Import statement (CSV)
                        </Button>
                        <InputError message={importForm.errors.file} />
                    </form>
                )}

                {imports.length > 0 && (
                    <section className="border-border bg-card rounded-lg border p-3 text-sm">
                        <h2 className="mb-2 font-semibold">{t('corr.acc.imports')}</h2>
                        <ul className="divide-border divide-y">
                            {imports.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        {item.filename} <span className="text-muted-foreground">({showDate(item.at)})</span>{' '}
                                        <span className="text-muted-foreground text-xs">
                                            {t('corr.acc.lines_count', { count: item.lines, matched: item.matched })}
                                        </span>
                                    </span>
                                    {can_remove &&
                                        (item.matched > 0 ? (
                                            <span className="flex max-w-64 flex-col items-start gap-0.5">
                                                <Button size="sm" variant="outline" disabled>
                                                    {t('corr.acc.remove_import')}
                                                </Button>
                                                <span className="text-muted-foreground text-[11px] leading-tight">
                                                    {t('corr.acc.import_matched')}
                                                </span>
                                            </span>
                                        ) : (
                                            <CorrectionReasonDialog
                                                trigger={
                                                    <Button size="sm" variant="outline">
                                                        {t('corr.acc.remove_import')}
                                                    </Button>
                                                }
                                                title={t('corr.acc.remove_import_title', { name: item.filename })}
                                                description={t('corr.acc.remove_import_help', { count: item.lines })}
                                                reasons={removeReasons}
                                                submitUrl={route('accounting.bank-statements.remove-import', item.id)}
                                                confirmLabel={t('corr.acc.remove_import')}
                                                destructive
                                            />
                                        ))}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="border-border bg-card rounded-lg border p-3">
                        <h2 className="mb-2 text-sm font-semibold">Statement lines ({statement_pages.total})</h2>
                        {statement_lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No statement imported for this account yet.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {statement_lines.map((line) => (
                                    <li key={line.id} className="py-2 text-sm">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span>
                                                <DateCell>{showDate(line.txn_date)}</DateCell>{' '}
                                                {line.description ?? line.reference ?? 'No description'}
                                            </span>
                                            <Amount value={money(line.amount)} tone={flow(line.amount)} />
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className={'text-xs ' + statusBadge(line.match_status)}>{line.match_status}</span>
                                            {can_act && (
                                                <span className="flex gap-1">
                                                    {line.match_status === 'unmatched' && (
                                                        <>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => setSelecting(selecting === line.id ? null : line.id)}
                                                            >
                                                                Match...
                                                            </Button>
                                                            <Button variant="ghost" size="sm" onClick={() => act(line.id, 'ignore')}>
                                                                Ignore
                                                            </Button>
                                                            {can_remove && (
                                                                <CorrectionReasonDialog
                                                                    trigger={
                                                                        <Button variant="ghost" size="sm">
                                                                            {t('corr.acc.remove_line')}
                                                                        </Button>
                                                                    }
                                                                    title={t('corr.acc.remove_line_title')}
                                                                    description={t('corr.acc.remove_line_help')}
                                                                    reasons={removeReasons}
                                                                    submitUrl={route('accounting.bank-statements.remove-line', line.id)}
                                                                    confirmLabel={t('corr.acc.remove_line')}
                                                                    destructive
                                                                />
                                                            )}
                                                        </>
                                                    )}
                                                    {line.match_status !== 'unmatched' && (
                                                        <Button variant="ghost" size="sm" onClick={() => act(line.id, 'unmatch')}>
                                                            Undo
                                                        </Button>
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        {selecting === line.id && (
                                            <div className="border-border mt-1 rounded border border-dashed p-2">
                                                <p className="text-muted-foreground mb-1 text-xs">
                                                    Pick the book line this statement row is (must agree to the paisa):
                                                </p>
                                                {match_candidates
                                                    .filter((book) => Math.abs(book.amount - Number(line.amount)) < 0.005)
                                                    .map((book) => (
                                                        <button
                                                            key={book.id}
                                                            type="button"
                                                            className="hover:bg-accent block w-full rounded px-2 py-1 text-left text-xs"
                                                            onClick={() => {
                                                                act(line.id, 'match', { journal_line_id: book.id });
                                                                setSelecting(null);
                                                            }}
                                                        >
                                                            {showDate(book.date)} · {book.journal} · {book.memo ?? ''} · {money(book.amount)}
                                                        </button>
                                                    ))}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <PageSwitch pages={statement_pages} param="page" />
                    </section>

                    <section className="border-border bg-card rounded-lg border p-3">
                        <h2 className="mb-2 text-sm font-semibold">Book lines with no statement line ({book_pages.total})</h2>
                        {unmatched_book_lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Every book movement has its statement line.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {unmatched_book_lines.map((book) => (
                                    <li key={book.id} className="flex items-baseline justify-between gap-3 py-2 text-sm">
                                        <span>
                                            <DateCell>{showDate(book.date)}</DateCell> <DocNo>{book.journal}</DocNo>
                                            {book.memo && <span className="text-muted-foreground"> · {book.memo}</span>}
                                        </span>
                                        <Amount value={money(book.amount)} tone={flow(book.amount)} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <PageSwitch pages={book_pages} param="book_page" />
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
