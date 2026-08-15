import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Reconciliation', href: '/accounting/reconciliation' },
];

export default function ReconciliationIndex({
    checks,
    problems,
    warnings,
    nothingReconciled,
}: {
    checks: number;
    problems: string[];
    warnings: string[];
    nothingReconciled: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reconciliation" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Reconciliation</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            Proves each subsidiary ledger agrees with its source documents: payables against supplier bills, receivables against
                            invoices and receipts, stock against its movements. A balanced trial balance does not prove the accounting is correct;
                            this is the check that gets closer.
                        </p>
                    </div>
                    {/* C-RSC-5: fresh on every load; the button just reloads. */}
                    <Button variant="outline" onClick={() => router.reload()}>
                        Run again
                    </Button>
                </div>

                {/* C-RSC-3: zero checks is NOT agreement, and the 13 Aug restore
                    drill is why this state gets its own loud box. */}
                {nothingReconciled ? (
                    <div className="rounded-md border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                        <p className="font-medium">Nothing was reconciled.</p>
                        <p>
                            No account mappings are configured, so every check was skipped. This is not a statement that the books agree. Seed the
                            account mappings, then run this again.
                        </p>
                    </div>
                ) : (
                    <div
                        className={`rounded-md border px-4 py-3 text-sm ${
                            problems.length === 0
                                ? 'border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border'
                                : 'border-destructive/40 bg-destructive/5 text-destructive font-medium'
                        }`}
                    >
                        {problems.length === 0
                            ? `Ran ${checks} reconciliation${checks === 1 ? '' : 's'}. Every subsidiary ledger agrees with its control account.`
                            : `Ran ${checks} reconciliation${checks === 1 ? '' : 's'}. ${problems.length} break${problems.length === 1 ? '' : 's'} found.`}
                    </div>
                )}

                {problems.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">Breaks</h2>
                        <ul className="flex flex-col gap-2">
                            {problems.map((problem, index) => (
                                <li
                                    key={index}
                                    className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-4 py-2 text-sm"
                                >
                                    {problem}
                                </li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            A break means a control account with postings disagrees with the documents behind it: a posting without a document, a
                            document without a posting, or one of the two recorded at the wrong amount. Each one needs a human to look at it; nothing
                            here self-heals on purpose.
                        </p>
                    </div>
                )}

                {/* C-RSC-4: warnings are named, never red. Unallocated money and
                    operations that predate accounting are normal states. */}
                {warnings.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">Worth a glance</h2>
                        <ul className="flex flex-col gap-2">
                            {warnings.map((warning, index) => (
                                <li
                                    key={index}
                                    className="rounded-md border border-amber-500/40 bg-amber-500/5 px-4 py-2 text-sm text-amber-700 dark:text-amber-400"
                                >
                                    {warning}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
