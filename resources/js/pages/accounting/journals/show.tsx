import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface Line {
    line_no: number;
    account: string;
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
    total: string;
    lines: Line[];
}

export default function ShowJournal({ journal }: { journal: Journal }) {
    const { canPostJournal, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: 'Journals', href: '/accounting/journals' },
        { title: journal.number, href: `/accounting/journals/${journal.id}` },
    ];

    const reverse = () => router.post(route('accounting.journals.reverse', journal.id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={journal.number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="font-mono text-lg font-semibold">{journal.number}</h1>
                            <Badge variant="outline">{journal.type_label}</Badge>
                            {journal.is_reversal && <Badge variant="secondary">Reverses {journal.reverses}</Badge>}
                            {journal.reversed && <Badge variant="destructive">Reversed</Badge>}
                        </div>
                        {journal.memo && <p className="text-sm">{journal.memo}</p>}
                        <p className="text-muted-foreground text-sm">
                            Dated {journal.date}
                            {journal.posted_by && ` · posted by ${journal.posted_by}`}
                            {journal.posted_at && ` on ${journal.posted_at}`}
                        </p>
                    </div>

                    {canPostJournal && !journal.reversed && !journal.is_reversal && (
                        <Button variant="outline" onClick={reverse}>
                            Reverse this journal
                        </Button>
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
                                    <td className="py-2">{line.account}</td>
                                    <td className="text-muted-foreground py-2">{line.memo}</td>
                                    <td className="py-2 text-right tabular-nums">{line.debit}</td>
                                    <td className="py-2 text-right tabular-nums">{line.credit}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-medium">
                                <td className="py-2" colSpan={3}>
                                    Total
                                </td>
                                <td className="py-2 text-right tabular-nums">{journal.total}</td>
                                <td className="py-2 text-right tabular-nums">{journal.total}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
