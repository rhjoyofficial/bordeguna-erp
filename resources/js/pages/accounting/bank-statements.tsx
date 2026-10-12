import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
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

interface BankStatementsProps {
    accounts: { id: number; name: string; type: string }[];
    money_account_id: number;
    statement_lines: StatementLine[];
    unmatched_book_lines: BookLine[];
    can_act: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bank statements', href: '/accounting/bank-statements' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function BankStatements({ accounts, money_account_id, statement_lines, unmatched_book_lines, can_act }: BankStatementsProps) {
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
                    <div>
                        <h1 className="text-xl font-semibold">Bank statements</h1>
                    </div>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={money_account_id}
                        onChange={(e) => router.get('/accounting/bank-statements', { money_account_id: e.target.value })}
                    >
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
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

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="border-border bg-card rounded-lg border p-3">
                        <h2 className="mb-2 text-sm font-semibold">Statement lines ({statement_lines.length})</h2>
                        {statement_lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No statement imported for this account yet.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {statement_lines.map((line) => (
                                    <li key={line.id} className="py-2 text-sm">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span>
                                                <span className="text-muted-foreground">{line.txn_date}</span>{' '}
                                                {line.description ?? line.reference ?? 'No description'}
                                            </span>
                                            <span className="font-semibold tabular-nums">{money(line.amount)}</span>
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
                                                {unmatched_book_lines
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
                                                            {book.date} · {book.journal} · {book.memo ?? ''} · {money(book.amount)}
                                                        </button>
                                                    ))}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="border-border bg-card rounded-lg border p-3">
                        <h2 className="mb-2 text-sm font-semibold">Book lines with no statement line ({unmatched_book_lines.length})</h2>
                        {unmatched_book_lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Every book movement has its statement line.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {unmatched_book_lines.map((book) => (
                                    <li key={book.id} className="flex items-baseline justify-between gap-3 py-2 text-sm">
                                        <span>
                                            <span className="text-muted-foreground">{book.date}</span> {book.journal}
                                            {book.memo && <span className="text-muted-foreground"> · {book.memo}</span>}
                                        </span>
                                        <span className="font-semibold tabular-nums">{money(book.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
