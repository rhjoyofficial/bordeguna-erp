import { Amount, DateCell, DocNo, MUTED_ROW, ReversalPill, TruncatedText } from '@/components/ledger-text';
import { EmptyRow, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { showDate } from '@/lib/dates';

/**
 * ⭐ The customer ledger view (party ledgers, 20 Oct 2026), the mirror of the
 * supplier ledger view: one customer across Accounts Receivable, Customer
 * Advances and any other account a posting named them on. Tally's ledger
 * under Sundry Debtors. Shared by the General Ledger (a customer's 1140-<id>
 * head) and the Customer ledger page.
 *
 * ⚠⚠ A VIEW, NOT AN ACCOUNT: the head holds nothing; every figure comes from
 * the customer's lines on the real accounts, each account tied out.
 */

type Side = 'debit' | 'credit';
export type CustomerLedgerRole = 'receivable' | 'advances' | 'other';

interface Signed {
    amount: string;
    negative: boolean;
}

export interface CustomerLedgerData {
    customer: { id: number; name: string; code: string | null; head: string | null };
    from: string;
    to: string;
    cards: { receivable: Signed; advances: Signed; net: Signed; open_orders: string };
    accounts: {
        id: number;
        code: string | null;
        name: string | null;
        role: CustomerLedgerRole;
        opening: string;
        opening_side: Side;
        debits: string;
        credits: string;
        closing: string;
        closing_side: Side;
        ledger_closing: string;
        ledger_closing_side: Side;
        consistent: boolean;
        lines: number;
    }[];
    opening_net: string;
    lines: {
        id: number;
        journal_id: number;
        journal_number: string;
        date: string;
        account_code: string | null;
        role: CustomerLedgerRole;
        document: { label: string; number: string | null; url: string | null };
        memo: string | null;
        reversed_by: { id: number; number: string } | null;
        reversal_of: { id: number; number: string } | null;
        debit: string | null;
        credit: string | null;
        balance: string;
        balance_side: Side;
        net: string | null;
    }[];
    commitments: { id: number; number: string; order_date: string | null; status: string; open: string; url: string | null }[];
    consistent: boolean;
}

const ROLE_TITLE: Record<CustomerLedgerRole, string> = {
    receivable: 'Invoiced (Accounts Receivable)',
    advances: 'Advances received',
    other: 'Other account',
};

const signed = (value: Signed) => (value.negative ? `-${value.amount}` : value.amount);
const sided = (amount: string, side: Side) => (amount === '0.00' ? amount : `${amount} ${side === 'credit' ? 'Cr' : 'Dr'}`);

export function CustomerLedgerView({ ledger }: { ledger: CustomerLedgerData }) {
    const { cards } = ledger;

    return (
        <div className="grid gap-6">
            <Verdict>
                {ledger.customer.head ? `${ledger.customer.head} is` : 'This is'} a view of {ledger.customer.name}&apos;s postings, not an account of
                its own: nothing is posted to it. Every figure below comes from the lines that name this customer on the real control accounts
                (Accounts Receivable, Customer Advances and any other account a posting named them on).
            </Verdict>

            {!ledger.consistent && (
                <Verdict tone="bad">
                    A control account below does not tie out: the lines listed do not add up to the customer&apos;s balance on it. Run
                    accounting:verify-ledger.
                </Verdict>
            )}

            <StatRow>
                <StatTile
                    label="Invoiced, not received"
                    value={<Amount value={signed(cards.receivable)} tone="balance" />}
                    hint={cards.receivable.negative ? 'Below zero: we owe the customer (Accounts Receivable)' : 'Accounts Receivable'}
                />
                <StatTile
                    label="Advances held"
                    value={<Amount value={signed(cards.advances)} tone="balance" />}
                    hint="Customer Advances, not yet used against an invoice"
                />
                <StatTile
                    label="Net owed to us"
                    value={<Amount value={signed(cards.net)} tone="balance" />}
                    hint="Invoiced less advances held. A management figure, never posted."
                />
                <StatTile
                    label="Open orders"
                    value={<Amount value={cards.open_orders} tone="balance" />}
                    hint="Still to pay on orders being delivered. Not in the books."
                />
            </StatRow>

            <section className="grid gap-2">
                <h2 className="text-sm font-semibold">Each control account, tied out</h2>
                <ReportTable
                    minWidth="52rem"
                    head={
                        <>
                            <th className="py-2">Control account</th>
                            <th className="py-2 text-right">Opening</th>
                            <th className="py-2 text-right">Debits</th>
                            <th className="py-2 text-right">Credits</th>
                            <th className="py-2 text-right">Closing</th>
                            <th className="py-2 text-right">Customer&apos;s balance in the ledger</th>
                            <th className="py-2 text-right">Ties out</th>
                        </>
                    }
                >
                    {ledger.accounts.map((account) => (
                        <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="py-2">
                                <div>{[account.code, account.name].filter(Boolean).join(' ')}</div>
                                <div className="text-muted-foreground text-xs">{ROLE_TITLE[account.role]}</div>
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={sided(account.opening, account.opening_side)} tone="balance" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={account.debits} tone="debit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={account.credits} tone="credit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={sided(account.closing, account.closing_side)} tone="total" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={sided(account.ledger_closing, account.ledger_closing_side)} tone="balance" />
                            </td>
                            <td
                                className={`py-2 text-right ${account.consistent ? 'text-emerald-700 dark:text-emerald-400' : 'text-destructive font-medium'}`}
                            >
                                {account.consistent ? 'Yes' : 'No'}
                            </td>
                        </tr>
                    ))}
                    {ledger.accounts.length === 0 && <EmptyRow colSpan={7}>No control account names this customer.</EmptyRow>}
                </ReportTable>
            </section>

            <section className="grid gap-2">
                <h2 className="text-sm font-semibold">Every posting, in date order</h2>
                <ReportTable
                    minWidth="64rem"
                    head={
                        <>
                            <th className="py-2">Date</th>
                            <th className="py-2">Account</th>
                            <th className="py-2">Document</th>
                            <th className="py-2">Description</th>
                            <th className="py-2 text-right">Debit</th>
                            <th className="py-2 text-right">Credit</th>
                            <th className="py-2 text-right">Account balance</th>
                            <th className="py-2 text-right">Net owed</th>
                        </>
                    }
                >
                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                        <td className="py-2">
                            <DateCell>{showDate(ledger.from)}</DateCell>
                        </td>
                        <td className="py-2 font-semibold" colSpan={6}>
                            Opening net owed
                        </td>
                        <td className="py-2 text-right">
                            <Amount value={ledger.opening_net} tone="balance" />
                        </td>
                    </tr>
                    {ledger.lines.map((line) => (
                        <tr
                            key={line.id}
                            className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${line.reversed_by || line.reversal_of ? MUTED_ROW : ''}`}
                        >
                            <td className="py-2 whitespace-nowrap">
                                <DateCell>{showDate(line.date)}</DateCell>
                            </td>
                            <td className="py-2">
                                <div className="whitespace-nowrap">{line.account_code}</div>
                                <div className="text-muted-foreground text-xs">{ROLE_TITLE[line.role]}</div>
                            </td>
                            <td className="py-2">
                                <div className="grid gap-0.5">
                                    <span>
                                        {line.document.label}
                                        {line.document.number ? (
                                            <>
                                                {' '}
                                                <DocNo href={line.document.url}>{line.document.number}</DocNo>
                                            </>
                                        ) : null}
                                    </span>
                                    <DocNo href={route('accounting.journals.show', line.journal_id)} className="text-xs">
                                        {line.journal_number}
                                    </DocNo>
                                </div>
                            </td>
                            <td className="text-muted-foreground max-w-0 py-2">
                                <div className="flex min-w-0 items-center gap-2">
                                    <TruncatedText text={line.memo} className="min-w-0 flex-1" />
                                    {line.reversed_by && (
                                        <ReversalPill
                                            kind="reversed-by"
                                            number={line.reversed_by.number}
                                            href={route('accounting.journals.show', line.reversed_by.id)}
                                        />
                                    )}
                                    {line.reversal_of && (
                                        <ReversalPill
                                            kind="reversal-of"
                                            number={line.reversal_of.number}
                                            href={route('accounting.journals.show', line.reversal_of.id)}
                                        />
                                    )}
                                </div>
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={line.debit} tone="debit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={line.credit} tone="credit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={sided(line.balance, line.balance_side)} tone="balance" />
                            </td>
                            <td className="py-2 text-right">
                                {line.net === null ? (
                                    <span className="text-muted-foreground text-xs">not in net</span>
                                ) : (
                                    <Amount value={line.net} tone="balance" />
                                )}
                            </td>
                        </tr>
                    ))}
                    {ledger.lines.length === 0 && <EmptyRow colSpan={8}>Nothing names this customer in this period.</EmptyRow>}
                </ReportTable>
            </section>

            {ledger.commitments.length > 0 && (
                <section className="grid gap-2">
                    <h2 className="text-sm font-semibold">Open orders (not in the books)</h2>
                    <ReportTable
                        minWidth="36rem"
                        head={
                            <>
                                <th className="py-2">Order</th>
                                <th className="py-2">Placed on</th>
                                <th className="py-2 text-right">Still to pay</th>
                            </>
                        }
                    >
                        {ledger.commitments.map((order) => (
                            <tr key={order.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <DocNo href={order.url}>{order.number}</DocNo>
                                </td>
                                <td className="py-2">
                                    <DateCell>{showDate(order.order_date)}</DateCell>
                                </td>
                                <td className="py-2 text-right">
                                    <Amount value={order.open} />
                                </td>
                            </tr>
                        ))}
                    </ReportTable>
                </section>
            )}
        </div>
    );
}
