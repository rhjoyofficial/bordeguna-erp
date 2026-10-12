import { Amount, DateCell, DocNo, LedgerHead, PartyText, StatusPill } from '@/components/ledger-text';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';
import { type VoucherTypeProps } from './form';

interface Row {
    id: number;
    title: string;
    number: string | null;
    date: string;
    status: 'draft' | 'pending_approval' | 'posted' | 'reversed';
    counterparty: string | null;
    money_account: string | null;
    to_money_account: string | null;
    particulars: string;
    narration: string | null;
    total: string;
    created_by: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const STATUS_LABEL: Record<Row['status'], string> = {
    draft: 'Draft',
    pending_approval: 'Awaiting approval',
    posted: 'Posted',
    reversed: 'Reversed',
};

/** A voucher's state in the registers' shared colours: posted green, draft grey, awaiting amber, reversed red. */
export function StatusBadge({ status }: { status: Row['status'] }) {
    return <StatusPill status={status} label={STATUS_LABEL[status]} />;
}

const TYPES: { value: VoucherTypeProps['value']; label: string }[] = [
    { value: 'payment', label: 'Payment' },
    { value: 'receipt', label: 'Receive' },
    { value: 'contra', label: 'Contra' },
    { value: 'journal', label: 'Journal' },
];

export default function VouchersIndex({
    type,
    filters,
    vouchers,
}: {
    type: VoucherTypeProps;
    filters: { status: string; from: string; to: string; search: string };
    vouchers: Paginated<Row>;
}) {
    const { canPrepareVouchers } = usePage<SharedData>().props;
    const [form, setForm] = useState(filters);

    const apply = (next: typeof form) => {
        setForm(next);
        const query = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== ''));
        router.get(route('accounting.vouchers.index', type.value), query, { preserveState: true, preserveScroll: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        apply(form);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/day-book' },
        { title: type.plural, href: route('accounting.vouchers.index', type.value) },
    ];

    const middle =
        type.value === 'contra'
            ? 'From → To'
            : type.value === 'journal'
              ? 'Particulars'
              : `${type.counterparty_label} · ${type.value === 'payment' ? 'paid from' : 'received into'}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={type.plural} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold">{type.plural}</h1>
                    </div>
                    {canPrepareVouchers && (
                        <Button asChild>
                            <Link href={route('accounting.vouchers.create', type.value)}>New {type.label.toLowerCase()}</Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap gap-1">
                    {TYPES.map((entry) => (
                        <Link
                            key={entry.value}
                            href={route('accounting.vouchers.index', entry.value)}
                            className={`rounded-md px-3 py-1.5 text-sm ${entry.value === type.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}
                        >
                            {entry.label}
                        </Link>
                    ))}
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <Input
                        className="w-64"
                        placeholder="Number, name, narration, cheque no."
                        value={form.search}
                        onChange={(e) => setForm({ ...form, search: e.target.value })}
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={form.from}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                        aria-label="From"
                    />
                    <Input type="date" className="w-40" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} aria-label="To" />
                    <SearchableSelect
                        fit
                        value={form.status}
                        onChange={(status) => apply({ ...form, status })}
                        options={[
                            { value: '', label: 'Any status' },
                            { value: 'draft', label: 'Draft' },
                            { value: 'pending_approval', label: 'Awaiting approval' },
                            { value: 'posted', label: 'Posted' },
                            { value: 'reversed', label: 'Reversed' },
                        ]}
                        placeholder="Any status"
                    />
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2 pr-3">Voucher</th>
                                <th className="py-2 pr-3">Date</th>
                                <th className="py-2 pr-3">{middle}</th>
                                <th className="py-2 pr-3">Narration</th>
                                <th className="py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground py-8 text-center">
                                        No {type.plural.toLowerCase()} {Object.values(filters).some((v) => v !== '') ? 'match this search' : 'yet'}.
                                    </td>
                                </tr>
                            )}
                            {vouchers.data.map((voucher) => (
                                <tr key={voucher.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                    <td className="py-2 pr-3">
                                        <DocNo href={route('accounting.vouchers.show', voucher.id)}>{voucher.number ?? 'Draft'}</DocNo>
                                        <div className="mt-1">
                                            <StatusBadge status={voucher.status} />
                                        </div>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <DateCell>{voucher.date}</DateCell>
                                    </td>
                                    <td className="py-2 pr-3">
                                        {type.value === 'contra' ? (
                                            <>
                                                <LedgerHead name={voucher.money_account ?? '-'} />
                                                <span className="text-muted-foreground"> → </span>
                                                <LedgerHead name={voucher.to_money_account ?? '-'} />
                                            </>
                                        ) : (
                                            <>
                                                <div>
                                                    {voucher.counterparty ? (
                                                        <PartyText>{voucher.counterparty}</PartyText>
                                                    ) : (
                                                        <LedgerHead label={voucher.particulars} />
                                                    )}
                                                </div>
                                                <div className="text-xs">
                                                    {voucher.counterparty && voucher.particulars ? <LedgerHead label={voucher.particulars} /> : null}
                                                    {voucher.counterparty && voucher.particulars && voucher.money_account ? (
                                                        <span className="text-muted-foreground"> · </span>
                                                    ) : null}
                                                    {voucher.money_account ? (
                                                        <span className="text-muted-foreground">{voucher.money_account}</span>
                                                    ) : null}
                                                </div>
                                            </>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2 pr-3">{voucher.narration}</td>
                                    <td className="py-2 text-right">
                                        <Amount value={voucher.total} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {vouchers.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {vouchers.links.map((link, index) =>
                            link.url === null ? (
                                <span
                                    key={index}
                                    className="text-muted-foreground px-3 py-1 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ),
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
