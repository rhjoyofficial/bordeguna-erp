import { Amount, DateCell, DocNo, MUTED_ROW, ReversalPill, TruncatedText } from '@/components/ledger-text';
import { EmptyRow, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { showDate } from '@/lib/dates';
import { Link } from '@inertiajs/react';

/**
 * ⭐ The supplier ledger view (A1, 18 Oct 2026): one supplier across every
 * control account a posting named them on. Shared by the General Ledger (a
 * supplier's label head), the Supplier ledger page and the supplier statement.
 *
 * ⚠⚠ A VIEW, NOT AN ACCOUNT. The "2110-<id>" head holds nothing; the page says
 * so, and every figure comes from the supplier's lines on the real accounts.
 * Gross figures first, net exposure beside them as a management figure.
 */

type Side = 'debit' | 'credit';
export type LedgerRole = 'payable' | 'grni' | 'advances' | 'other';

interface Signed {
    amount: string;
    negative: boolean;
}

export interface SupplierLedgerAccount {
    id: number;
    code: string | null;
    name: string | null;
    role: LedgerRole;
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
}

export interface SupplierLedgerLine {
    id: number;
    journal_id: number;
    journal_number: string;
    date: string;
    account_id: number;
    account_code: string | null;
    account_name: string | null;
    role: LedgerRole;
    document: { label: string; number: string | null; url: string | null };
    po_number: string | null;
    po_url: string | null;
    challan: string | null;
    memo: string | null;
    reversed_by: { id: number; number: string } | null;
    reversal_of: { id: number; number: string } | null;
    debit: string | null;
    credit: string | null;
    balance: string;
    balance_side: Side;
    exposure: string | null;
}

export interface SupplierLedgerData {
    supplier: { id: number; name: string; code: string | null; head: string | null };
    from: string;
    to: string;
    cards: {
        billed_payable: Signed;
        received_not_billed: Signed;
        advances: Signed;
        net_exposure: Signed;
        open_commitments: string;
    };
    accounts: SupplierLedgerAccount[];
    opening_exposure: string;
    lines: SupplierLedgerLine[];
    commitments: { id: number; number: string; order_date: string | null; status: string; open: string; url: string | null }[];
    untagged_grni: { amount: string; lines: number };
    consistent: boolean;
}

const ROLE_TITLE: Record<LedgerRole, string> = {
    payable: 'Billed (Accounts Payable)',
    grni: 'Received, not billed (GRNI)',
    advances: 'Advances paid',
    other: 'Other account',
};

const signed = (value: Signed) => (value.negative ? `-${value.amount}` : value.amount);
const sided = (amount: string, side: Side) => (amount === '0.00' ? amount : `${amount} ${side === 'credit' ? 'Cr' : 'Dr'}`);
const accountLabel = (account: { code: string | null; name: string | null }) => [account.code, account.name].filter(Boolean).join(' ');

function DocumentCell({ line }: { line: SupplierLedgerLine }) {
    return (
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
    );
}

function References({ line }: { line: SupplierLedgerLine }) {
    if (!line.po_number && !line.challan) {
        return null;
    }

    return (
        <div className="grid gap-0.5 text-xs">
            {line.po_number && <DocNo href={line.po_url}>{line.po_number}</DocNo>}
            {line.challan && <span className="text-muted-foreground">Challan {line.challan}</span>}
        </div>
    );
}

function Memo({ line }: { line: SupplierLedgerLine }) {
    return (
        <div className="flex min-w-0 items-center gap-2">
            <TruncatedText text={line.memo} className="min-w-0 flex-1" />
            {line.reversed_by && (
                <ReversalPill kind="reversed-by" number={line.reversed_by.number} href={route('accounting.journals.show', line.reversed_by.id)} />
            )}
            {line.reversal_of && (
                <ReversalPill kind="reversal-of" number={line.reversal_of.number} href={route('accounting.journals.show', line.reversal_of.id)} />
            )}
        </div>
    );
}

/** Per control account: what the listing adds up to against the supplier's own balance on that account. */
export function SupplierLedgerTieOut({ ledger, roles }: { ledger: SupplierLedgerData; roles?: LedgerRole[] }) {
    const accounts = roles ? ledger.accounts.filter((account) => roles.includes(account.role)) : ledger.accounts;

    return (
        <ReportTable
            minWidth="52rem"
            head={
                <>
                    <th className="py-2">Control account</th>
                    <th className="py-2 text-right">Opening</th>
                    <th className="py-2 text-right">Debits</th>
                    <th className="py-2 text-right">Credits</th>
                    <th className="py-2 text-right">Closing</th>
                    <th className="py-2 text-right">Supplier&apos;s balance in the ledger</th>
                    <th className="py-2 text-right">Ties out</th>
                </>
            }
        >
            {accounts.map((account) => (
                <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                    <td className="py-2">
                        <div>{accountLabel(account)}</div>
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
            {accounts.length === 0 && <EmptyRow colSpan={7}>No control account names this supplier.</EmptyRow>}
        </ReportTable>
    );
}

/** One control account's lines for the supplier, with that account's running balance (the statement's extra sections). */
export function SupplierLedgerAccountLines({ ledger, role }: { ledger: SupplierLedgerData; role: LedgerRole }) {
    const accounts = ledger.accounts.filter((account) => account.role === role);
    const lines = ledger.lines.filter((line) => line.role === role);

    return (
        <ReportTable
            minWidth="56rem"
            head={
                <>
                    <th className="py-2">Date</th>
                    <th className="py-2">Document</th>
                    <th className="py-2">PO / challan</th>
                    <th className="py-2">Description</th>
                    <th className="py-2 text-right">Debit</th>
                    <th className="py-2 text-right">Credit</th>
                    <th className="py-2 text-right">Balance</th>
                </>
            }
        >
            {accounts.map((account) => (
                <tr key={`opening-${account.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                    <td className="py-2">
                        <DateCell>{showDate(ledger.from)}</DateCell>
                    </td>
                    <td className="py-2 font-semibold" colSpan={5}>
                        Opening balance{accounts.length > 1 ? `, ${accountLabel(account)}` : ''}
                    </td>
                    <td className="py-2 text-right">
                        <Amount value={sided(account.opening, account.opening_side)} tone="balance" />
                    </td>
                </tr>
            ))}
            {lines.map((line) => (
                <tr
                    key={line.id}
                    className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${line.reversed_by || line.reversal_of ? MUTED_ROW : ''}`}
                >
                    <td className="py-2 whitespace-nowrap">
                        <DateCell>{showDate(line.date)}</DateCell>
                    </td>
                    <td className="py-2">
                        <DocumentCell line={line} />
                    </td>
                    <td className="py-2">
                        <References line={line} />
                    </td>
                    <td className="text-muted-foreground max-w-0 py-2">
                        <Memo line={line} />
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
                </tr>
            ))}
            {lines.length === 0 && <EmptyRow colSpan={7}>Nothing in this period.</EmptyRow>}
        </ReportTable>
    );
}

export function SupplierLedgerView({ ledger }: { ledger: SupplierLedgerData }) {
    const { cards } = ledger;

    return (
        <div className="grid gap-6">
            <Verdict>
                {ledger.supplier.head ? `${ledger.supplier.head} is` : 'This is'} a view of {ledger.supplier.name}&apos;s postings, not an account of
                its own: nothing is posted to it. Every figure below comes from the lines that name this supplier on the real control accounts
                (Accounts Payable, Goods Received Not Invoiced, Advances to Suppliers and any other account a posting named them on).
            </Verdict>

            {!ledger.consistent && (
                <Verdict tone="bad">
                    A control account below does not tie out: the lines listed do not add up to the supplier&apos;s balance on it. Run
                    accounting:verify-ledger.
                </Verdict>
            )}

            <StatRow>
                <StatTile
                    label="Billed, not paid"
                    value={<Amount value={signed(cards.billed_payable)} tone="balance" />}
                    hint={cards.billed_payable.negative ? 'Below zero: the supplier owes us (Accounts Payable)' : 'Accounts Payable'}
                />
                <StatTile
                    label="Received, not billed"
                    value={<Amount value={signed(cards.received_not_billed)} tone="balance" />}
                    hint="Goods Received Not Invoiced, tagged to this supplier"
                />
                <StatTile
                    label="Advances paid"
                    value={<Amount value={signed(cards.advances)} tone="balance" />}
                    hint="Advances to Suppliers, not yet applied"
                />
                <StatTile
                    label="Net exposure"
                    value={<Amount value={signed(cards.net_exposure)} tone="balance" />}
                    hint="Billed plus received, less advances. A management figure, never posted."
                />
            </StatRow>

            <div className="text-muted-foreground text-sm">
                Open purchase orders, ordered and not yet received: <span className="text-foreground font-medium">{cards.open_commitments}</span>. A
                commitment, not a liability: it is in none of the figures above.
            </div>

            {ledger.untagged_grni.lines > 0 && (
                <Verdict>
                    {ledger.untagged_grni.lines} older Goods Received Not Invoiced line(s) belong to this supplier through their documents but name no
                    supplier in the ledger ({ledger.untagged_grni.amount} still open on them). They are not in the figures above.{' '}
                    <Link href={route('accounting.grni-untagged')} className="underline">
                        See GRNI not tagged to a supplier
                    </Link>
                    .
                </Verdict>
            )}

            <section className="grid gap-2">
                <h2 className="text-sm font-semibold">Each control account, tied out</h2>
                <SupplierLedgerTieOut ledger={ledger} />
            </section>

            <section className="grid gap-2">
                <h2 className="text-sm font-semibold">Every posting, in date order</h2>
                <ReportTable
                    minWidth="72rem"
                    head={
                        <>
                            <th className="py-2">Date</th>
                            <th className="py-2">Account</th>
                            <th className="py-2">Document</th>
                            <th className="py-2">PO / challan</th>
                            <th className="py-2">Description</th>
                            <th className="py-2 text-right">Debit</th>
                            <th className="py-2 text-right">Credit</th>
                            <th className="py-2 text-right">Account balance</th>
                            <th className="py-2 text-right">Net exposure</th>
                        </>
                    }
                >
                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                        <td className="py-2">
                            <DateCell>{showDate(ledger.from)}</DateCell>
                        </td>
                        <td className="py-2 font-semibold" colSpan={7}>
                            Opening net exposure
                        </td>
                        <td className="py-2 text-right">
                            <Amount value={ledger.opening_exposure} tone="balance" />
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
                                <DocumentCell line={line} />
                            </td>
                            <td className="py-2">
                                <References line={line} />
                            </td>
                            <td className="text-muted-foreground max-w-0 py-2">
                                <Memo line={line} />
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
                                {line.exposure === null ? (
                                    <span className="text-muted-foreground text-xs">not in exposure</span>
                                ) : (
                                    <Amount value={line.exposure} tone="balance" />
                                )}
                            </td>
                        </tr>
                    ))}
                    {ledger.lines.length === 0 && <EmptyRow colSpan={9}>Nothing names this supplier in this period.</EmptyRow>}
                </ReportTable>
            </section>

            {ledger.commitments.length > 0 && (
                <section className="grid gap-2">
                    <h2 className="text-sm font-semibold">Open purchase orders (not in the books)</h2>
                    <ReportTable
                        minWidth="36rem"
                        head={
                            <>
                                <th className="py-2">Order</th>
                                <th className="py-2">Ordered on</th>
                                <th className="py-2 text-right">Not yet received</th>
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
