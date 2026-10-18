import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, Fragment, useState } from 'react';

interface Cheque {
    voucher_id: number;
    voucher: string | null;
    direction: 'issued' | 'received';
    number: string | null;
    cheque_date: string | null;
    bank: string | null;
    account: string | null;
    party: string | null;
    amount: string;
    status: string;
    status_on: string | null;
    note: string | null;
    can_update: boolean;
}

interface Props {
    cheques: Cheque[];
    filters: { direction: string; status: string };
    statuses: Record<string, string>;
    canUpdate: boolean;
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cheques', href: '/accounting/cheques' }];

const tone = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' =>
    status === 'cleared' ? 'default' : status === 'pending' ? 'secondary' : status === 'bounced' ? 'destructive' : 'outline';

function StatusForm({ cheque, today, onDone }: { cheque: Cheque; today: string; onDone: () => void }) {
    const form = useForm({ status: 'cleared', status_on: today, note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.cheques.update', cheque.voucher_id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 py-2">
            <div className="flex flex-col gap-1">
                <Label htmlFor={`status-${cheque.voucher_id}`} required>
                    What happened
                </Label>
                <SearchSelect
                    fit
                    id={`status-${cheque.voucher_id}`}
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                >
                    <option value="cleared">Cleared</option>
                    <option value="bounced">Bounced (reverses the voucher)</option>
                    <option value="cancelled">Cancelled (reverses the voucher)</option>
                </SearchSelect>
                <InputError message={form.errors.status} />
            </div>
            <div className="flex flex-col gap-1">
                <Label htmlFor={`on-${cheque.voucher_id}`} required>
                    On
                </Label>
                <Input
                    id={`on-${cheque.voucher_id}`}
                    type="date"
                    className="w-auto"
                    required
                    value={form.data.status_on}
                    onChange={(e) => form.setData('status_on', e.target.value)}
                />
                <InputError message={form.errors.status_on} />
            </div>
            <div className="flex flex-col gap-1">
                <Label htmlFor={`note-${cheque.voucher_id}`}>Note</Label>
                <Input
                    id={`note-${cheque.voucher_id}`}
                    className="w-64"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </div>
            <Button size="sm" disabled={form.processing}>
                Save
            </Button>
            <Button size="sm" type="button" variant="outline" onClick={onDone}>
                Cancel
            </Button>
        </form>
    );
}

export default function ChequeRegister({ cheques, filters, statuses, canUpdate, today }: Props) {
    const [open, setOpen] = useState<number | null>(null);

    const filter = (key: 'direction' | 'status', value: string) =>
        router.get('/accounting/cheques', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cheques" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <p className="text-muted-foreground text-sm">
                        Every cheque on a posted voucher. Mark each one cleared when the bank shows it; a bounced or cancelled cheque reverses its
                        voucher.
                    </p>
                </div>

                <div className="flex flex-wrap gap-4">
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="direction">Direction</Label>
                        <SearchSelect fit id="direction" value={filters.direction} onChange={(e) => filter('direction', e.target.value)}>
                            <option value="">All</option>
                            <option value="issued">Issued by us</option>
                            <option value="received">Received</option>
                        </SearchSelect>
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="status">Status</Label>
                        <SearchSelect fit id="status" value={filters.status} onChange={(e) => filter('status', e.target.value)}>
                            <option value="">All</option>
                            {Object.entries(statuses).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
                    {cheques.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No cheques. A voucher whose instrument is Cheque appears here once posted.</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Cheque</th>
                                    <th className="py-2 pr-3">Date</th>
                                    <th className="py-2 pr-3">Party</th>
                                    <th className="py-2 pr-3 text-right">Amount</th>
                                    <th className="py-2 pr-3">Voucher</th>
                                    <th className="py-2 pr-3">Status</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {cheques.map((c) => (
                                    <Fragment key={c.voucher_id}>
                                        <tr className="border-b align-top">
                                            <td className="py-2 pr-3 font-medium">
                                                {c.number ?? '-'}
                                                <div className="text-muted-foreground text-xs">
                                                    {c.direction === 'issued' ? 'Issued' : 'Received'}
                                                    {c.bank && ` · ${c.bank}`}
                                                </div>
                                            </td>
                                            <td className="py-2 pr-3 whitespace-nowrap">{showDate(c.cheque_date) || '-'}</td>
                                            <td className="py-2 pr-3">
                                                {c.party ?? '-'}
                                                {c.account && <div className="text-muted-foreground text-xs">Our account: {c.account}</div>}
                                            </td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{c.amount}</td>
                                            <td className="py-2 pr-3">
                                                <Link href={`/accounting/vouchers/doc/${c.voucher_id}`} className="hover:underline">
                                                    {c.voucher}
                                                </Link>
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Badge variant={tone(c.status)}>{statuses[c.status] ?? c.status}</Badge>
                                                {c.status_on && <div className="text-muted-foreground text-xs">{showDate(c.status_on)}</div>}
                                                {c.note && <div className="text-muted-foreground text-xs">{c.note}</div>}
                                            </td>
                                            <td className="py-2 text-right">
                                                {canUpdate && c.can_update && open !== c.voucher_id && (
                                                    <button type="button" className="text-xs underline" onClick={() => setOpen(c.voucher_id)}>
                                                        Update
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                        {open === c.voucher_id && (
                                            <tr className="border-b">
                                                <td colSpan={7}>
                                                    <StatusForm cheque={c} today={today} onDone={() => setOpen(null)} />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
