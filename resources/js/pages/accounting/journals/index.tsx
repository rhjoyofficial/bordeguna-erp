import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface JournalRow {
    id: number;
    number: string;
    date: string;
    type: string;
    type_label: string;
    memo: string | null;
    total: string;
    lines: number;
    is_reversal: boolean;
    reversed: boolean;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Journals', href: '/accounting/journals' },
];

export default function JournalsIndex({ journals }: { journals: Paginated<JournalRow> }) {
    const { canPostJournal, canPrepareVouchers } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Journals" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold">Journals</h1>
                        <p className="text-muted-foreground text-sm">
                            Every entry in the general ledger. Nothing here can be edited or deleted; a mistake is corrected by reversing it, which
                            leaves both the error and the correction on the record.
                        </p>
                    </div>
                    {/* 26 Sep 2026: a hand-written entry is a Journal voucher (draft, print,
                        reverse-and-copy, prepare/post split). The Owner-only manual screen
                        stays reachable by its address for the rare case, not from here. */}
                    {canPrepareVouchers ? (
                        <Button asChild>
                            <Link href="/accounting/vouchers/journal/create">New journal voucher</Link>
                        </Button>
                    ) : (
                        canPostJournal && (
                            <Button asChild>
                                <Link href={route('accounting.journals.create')}>New journal</Link>
                            </Button>
                        )
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[42rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2">Number</th>
                                <th className="py-2">Date</th>
                                <th className="py-2">For</th>
                                <th className="py-2 text-right">Amount</th>
                                <th className="py-2 text-right">Lines</th>
                            </tr>
                        </thead>
                        <tbody>
                            {journals.data.map((journal) => (
                                <tr key={journal.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        <Link
                                            href={route('accounting.journals.show', journal.id)}
                                            className="font-mono underline-offset-4 hover:underline"
                                        >
                                            {journal.number}
                                        </Link>
                                        {journal.is_reversal && (
                                            <Badge variant="secondary" className="ml-2">
                                                Reversal
                                            </Badge>
                                        )}
                                        {journal.reversed && (
                                            <Badge variant="destructive" className="ml-2">
                                                Reversed
                                            </Badge>
                                        )}
                                        {journal.type === 'opening_balance' && (
                                            <Badge variant="outline" className="ml-2">
                                                Opening
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2 tabular-nums">{journal.date}</td>
                                    <td className="py-2">{journal.memo ?? <span className="text-muted-foreground">{journal.type_label}</span>}</td>
                                    <td className="py-2 text-right tabular-nums">{journal.total}</td>
                                    <td className="text-muted-foreground py-2 text-right tabular-nums">{journal.lines}</td>
                                </tr>
                            ))}
                            {journals.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                        Nothing has been posted to the ledger yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {journals.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {journals.links.map((link, index) => (
                            <Button key={index} variant={link.active ? 'secondary' : 'ghost'} size="sm" asChild={!!link.url} disabled={!link.url}>
                                {link.url ? (
                                    <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
