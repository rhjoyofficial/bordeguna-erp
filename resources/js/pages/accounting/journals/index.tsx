import { Pagination } from '@/components/data-table/pagination';
import { Amount, DateCell, DocNo, MUTED_ROW, StatusPill, TruncatedText } from '@/components/ledger-text';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Journal register', href: '/accounting/journals' },
];

export default function JournalsIndex({
    journals,
    type,
    types,
}: {
    journals: Paginated<JournalRow>;
    type: string | null;
    types: { value: string; label: string }[];
}) {
    const { canPostJournal, canPrepareVouchers } = usePage<SharedData>().props;
    const { t } = useTranslation();

    // ACC-011 (14 Oct 2026): filter by type, e.g. "Opening balance" before
    // entering the opening journal, so it is never entered twice.
    const filterBy = (next: string) =>
        router.get(route('accounting.journals.index'), next === '' ? {} : { type: next }, { preserveState: true, preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Journal register" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-end gap-4">
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

                <div className="flex flex-wrap items-center gap-3">
                    <SearchableSelect
                        fit
                        clearable
                        value={type ?? ''}
                        onChange={filterBy}
                        options={[{ value: '', label: t('books.journal.all_types') }, ...types]}
                        placeholder={t('books.journal.all_types')}
                    />
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[42rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="w-px py-2 whitespace-nowrap">Number</th>
                                <th className="w-px py-2 whitespace-nowrap">Date</th>
                                <th className="py-2">For</th>
                                <th className="w-px py-2 text-right whitespace-nowrap">Amount</th>
                                <th className="w-px py-2 text-right whitespace-nowrap">Lines</th>
                            </tr>
                        </thead>
                        <tbody>
                            {journals.data.map((journal) => (
                                <tr
                                    key={journal.id}
                                    className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${journal.reversed || journal.is_reversal ? MUTED_ROW : ''}`}
                                >
                                    <td className="py-2 whitespace-nowrap">
                                        <DocNo href={route('accounting.journals.show', journal.id)}>{journal.number}</DocNo>
                                        {journal.is_reversal && (
                                            <Badge variant="secondary" className="ml-2">
                                                Reversal
                                            </Badge>
                                        )}
                                        {journal.reversed && <StatusPill status="reversed" label="Reversed" className="ml-2" />}
                                        {journal.type === 'opening_balance' && (
                                            <Badge variant="outline" className="ml-2">
                                                Opening
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 whitespace-nowrap">
                                        <DateCell>{showDate(journal.date)}</DateCell>
                                    </td>
                                    <td className="text-muted-foreground max-w-0 py-2">
                                        <TruncatedText text={journal.memo ?? journal.type_label} />
                                    </td>
                                    <td className="py-2 text-right whitespace-nowrap">
                                        <Amount value={journal.total} />
                                    </td>
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

                <Pagination meta={journals} />
            </div>
        </AppLayout>
    );
}
