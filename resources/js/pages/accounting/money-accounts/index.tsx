import { EmployeeCashPosition } from '@/components/accounting/employee-cash-card';
import { MoneyAccountDialog, type DialogMoneyAccount, type MoneyAccountOption } from '@/components/accounting/money-account-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

// The dialog carries the fields the form edits; the list adds what it shows.
interface MoneyAccount extends DialogMoneyAccount {
    type_label: string;
    is_unmapped: boolean;
    /** Current balance from the posted journal lines, formatted by the server (F32). */
    balance: string;
    balance_is_negative: boolean;
    /** Initial plan B3: an Employee cash account's holder and what he holds or is owed. */
    custodian?: string | null;
    position?: 'holds' | 'owed' | 'even';
    position_amount?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Bank & cash', href: '/accounting/money-accounts' },
];

export default function MoneyAccountsIndex({
    accounts,
    types,
    ledgerAccounts,
    unmappedCount,
    courierLedgerAccountId,
    gatewayLedgerAccountId,
    employees = [],
}: {
    employees?: { id: number; name: string }[];
    accounts: MoneyAccount[];
    types: MoneyAccountOption[];
    ledgerAccounts: MoneyAccountOption[];
    unmappedCount: number;
    courierLedgerAccountId: number | null;
    gatewayLedgerAccountId: number | null;
}) {
    const { canManageMoneyAccounts } = usePage<SharedData>().props;
    // ⚠ Add and edit happen in a popup (Joy, 14 Oct 2026: "click add button
    // pop up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<MoneyAccount | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank & cash" />
            <div className="flex flex-col gap-6 p-4">
                {/*
                  ⚠⚠ An unmapped money account does not fail here. It fails at the
                  till, when somebody pays a supplier or takes a customer receipt,
                  with an error naming an account nobody remembers creating. The
                  account is deliberately allowed to exist before its mapping is
                  decided - so the unfinished state is made loud instead.
                */}
                {unmappedCount > 0 && (
                    <div className="border-destructive/50 bg-destructive/10 rounded-md border p-3 text-sm">
                        <p className="font-medium">
                            {unmappedCount} account{unmappedCount === 1 ? '' : 's'} {unmappedCount === 1 ? 'has' : 'have'} no ledger account.
                        </p>
                        <p className="text-muted-foreground">
                            Payments and customer receipts using {unmappedCount === 1 ? 'it' : 'them'} will be refused when somebody tries to record
                            one. Open Edit on {unmappedCount === 1 ? 'it' : 'each'} below and save to finish the setup.
                        </p>
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        Every cash box, bank account, wallet, courier and gateway the business keeps money in.
                    </p>
                    {canManageMoneyAccounts && <Button onClick={() => setDialog(null)}>Add account</Button>}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2">Name</th>
                                <th className="py-2">Kind</th>
                                <th className="py-2">Details</th>
                                <th className="py-2">Reports under</th>
                                <th className="py-2 text-right">Balance</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {/* ⭐ B23 (25 Oct 2026): each account opens its lifetime page. */}
                                        <Link href={`/accounting/money-accounts/${account.id}`} className="underline-offset-2 hover:underline">
                                            {account.name}
                                        </Link>
                                        {!account.is_active && (
                                            <Badge variant="destructive" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {account.type_label}
                                        {account.custodian && <span className="block text-xs">{account.custodian}</span>}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {[
                                            account.bank_name,
                                            account.branch,
                                            account.provider,
                                            account.account_number,
                                            account.routing_number ? `Routing ${account.routing_number}` : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {account.ledger_account ?? (
                                            /* ⚠⚠ Not a cosmetic gap. A payment from an unmapped
                                               account fails at the till with MappingNotFound, long
                                               after whoever created it has left this screen. */
                                            <span className="text-destructive font-medium">
                                                Not set - payments and receipts from this account will fail
                                            </span>
                                        )}
                                    </td>
                                    <td
                                        className={`py-2 text-right font-medium tabular-nums ${account.balance_is_negative ? 'text-destructive' : ''}`}
                                    >
                                        {account.balance}
                                        {account.position && (
                                            <span className="block text-xs">
                                                <EmployeeCashPosition position={account.position} amount={account.position_amount} />
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {canManageMoneyAccounts && (
                                            <Button variant="ghost" size="sm" onClick={() => setDialog(account)}>
                                                Edit
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {accounts.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                        No bank or cash accounts yet. Opening balances need these first.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {canManageMoneyAccounts && (
                    <MoneyAccountDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        account={dialog ?? null}
                        types={types}
                        ledgerAccounts={ledgerAccounts}
                        courierLedgerAccountId={courierLedgerAccountId}
                        gatewayLedgerAccountId={gatewayLedgerAccountId}
                        employees={employees}
                    />
                )}
            </div>
        </AppLayout>
    );
}
