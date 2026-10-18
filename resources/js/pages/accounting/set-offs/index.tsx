import { type PartyPosition } from '@/components/accounting/party-card';
import { ActionDialog } from '@/components/action-dialog';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Party {
    supplier_id: number;
    customer_id: number;
    label: string;
    position: PartyPosition;
}

interface OpenDocument {
    id: number;
    party_id: number;
    number: string;
    date: string | null;
    outstanding: string;
}

interface Availability {
    action: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string }[];
    date: { rule: string } | null;
}

interface Row {
    id: number;
    number: string;
    date: string | null;
    supplier: string | null;
    customer: string | null;
    amount: string;
    status: 'pending' | 'posted' | 'rejected' | 'reversed';
    status_label: string;
    bills: string[];
    invoices: string[];
    reason: string;
    by: string | null;
    journal: string | null;
    reversal: { on: string | null; by: string | null; reason: string | null } | null;
    actions: Availability[];
}

interface Props {
    setOffs: Row[];
    parties: Party[];
    openBills: OpenDocument[];
    openInvoices: OpenDocument[];
    canRaise: boolean;
    chosenSupplier: number | null;
    reversalReasons: { id: number; name: string }[];
    today: string;
}

type FormData = {
    supplier_id: string;
    set_off_on: string;
    invoices: Record<string, string>;
    bills: Record<string, string>;
    reason: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Set-offs', href: '/accounting/set-offs' },
];

/** Paisa from a typed taka figure, so the two sides compare exactly. */
const paisa = (value: string | undefined) => Math.round(Number(value || 0) * 100);
const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Documents({
    title,
    rows,
    values,
    onChange,
}: {
    title: string;
    rows: OpenDocument[];
    values: Record<string, string>;
    onChange: (id: number, value: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <Label>{title}</Label>
            {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('set_off.nothing_open')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-1 pr-3">{t('set_off.number')}</th>
                                <th className="py-1 pr-3">{t('set_off.date')}</th>
                                <th className="py-1 pr-3 text-right">{t('set_off.still_owed')}</th>
                                <th className="py-1 text-right">{t('set_off.clear')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className="border-b">
                                    <td className="py-1 pr-3">{row.number}</td>
                                    <td className="py-1 pr-3">{row.date}</td>
                                    <td className="py-1 pr-3 text-right tabular-nums">{taka(paisa(row.outstanding))}</td>
                                    <td className="py-1 text-right">
                                        <Input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            max={row.outstanding}
                                            className="ml-auto w-32 text-right"
                                            value={values[row.id] ?? ''}
                                            onChange={(e) => onChange(row.id, e.target.value)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function RaiseForm({ parties, openBills, openInvoices, chosenSupplier, today }: Omit<Props, 'setOffs' | 'canRaise' | 'reversalReasons'>) {
    const { t } = useTranslation();
    const form = useForm<FormData>({
        supplier_id: chosenSupplier && parties.some((p) => p.supplier_id === chosenSupplier) ? String(chosenSupplier) : '',
        set_off_on: today,
        invoices: {},
        bills: {},
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const party = parties.find((p) => String(p.supplier_id) === form.data.supplier_id) ?? null;
    const bills = party ? openBills.filter((b) => b.party_id === party.supplier_id) : [];
    const invoices = party ? openInvoices.filter((i) => i.party_id === party.customer_id) : [];
    const billsTotal = Object.values(form.data.bills).reduce((sum, v) => sum + paisa(v), 0);
    const invoicesTotal = Object.values(form.data.invoices).reduce((sum, v) => sum + paisa(v), 0);
    const balanced = billsTotal > 0 && billsTotal === invoicesTotal;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.set-offs.store'), {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, invoices: {}, bills: {}, reason: '' }),
        });
    };

    if (parties.length === 0) {
        return <p className="text-muted-foreground text-sm">{t('set_off.no_parties')}</p>;
    }

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4">
            <h2 className="text-sm font-semibold">{t('set_off.raise')}</h2>
            <div className="flex flex-wrap items-start gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="supplier_id" required>
                        {t('set_off.party')}
                    </Label>
                    <SearchSelect
                        fit
                        id="supplier_id"
                        value={form.data.supplier_id}
                        onChange={(e) => form.setData({ ...form.data, supplier_id: e.target.value, invoices: {}, bills: {} })}
                    >
                        <option value="">{t('set_off.choose_party')}</option>
                        {parties.map((p) => (
                            <option key={p.supplier_id} value={p.supplier_id}>
                                {p.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={errors.supplier_id ?? errors.customer_id} />
                </div>
                <div className="flex flex-col gap-1">
                    <Label htmlFor="set_off_on" required>
                        {t('set_off.date')}
                    </Label>
                    <Input
                        id="set_off_on"
                        type="date"
                        className="w-auto"
                        value={form.data.set_off_on}
                        onChange={(e) => form.setData('set_off_on', e.target.value)}
                    />
                    <InputError message={errors.set_off_on} />
                </div>
            </div>

            {party && (
                <>
                    <p className="text-muted-foreground text-sm">
                        {t('party.we_owe')}: <span className="tabular-nums">{party.position.we_owe}</span> · {t('party.they_owe')}:{' '}
                        <span className="tabular-nums">{party.position.they_owe}</span> ·{' '}
                        {t('set_off.settleable', { amount: party.position.settleable })}
                    </p>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Documents
                            title={t('set_off.open_bills')}
                            rows={bills}
                            values={form.data.bills}
                            onChange={(id, value) => form.setData('bills', { ...form.data.bills, [id]: value })}
                        />
                        <Documents
                            title={t('set_off.open_invoices')}
                            rows={invoices}
                            values={form.data.invoices}
                            onChange={(id, value) => form.setData('invoices', { ...form.data.invoices, [id]: value })}
                        />
                    </div>
                    <p className="text-sm tabular-nums">
                        {t('set_off.bills_total')}: {taka(billsTotal)} · {t('set_off.invoices_total')}: {taka(invoicesTotal)}
                        {!balanced && (billsTotal > 0 || invoicesTotal > 0) && (
                            <span className="ml-2 text-amber-600 dark:text-amber-500">{t('set_off.must_match')}</span>
                        )}
                    </p>
                </>
            )}
            <InputError message={errors.bills ?? errors.invoices ?? errors.set_off} />

            <div className="flex flex-col gap-1">
                <Label htmlFor="reason" required>
                    {t('set_off.reason')}
                </Label>
                <Input id="reason" className="max-w-xl" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                <InputError message={errors.reason} />
            </div>

            <div>
                <Button disabled={form.processing || !balanced || form.data.reason.trim() === ''}>{t('set_off.submit')}</Button>
            </div>
        </form>
    );
}

function StatusBadge({ row }: { row: Row }) {
    const { tr } = useTranslation();
    const variant = row.status === 'posted' ? 'default' : row.status === 'pending' ? 'outline' : 'secondary';

    return <Badge variant={variant}>{tr(`set_off.status_${row.status}`, row.status_label)}</Badge>;
}

/**
 * ⭐ Set-offs (decision D-F, 18 Oct 2026): one firm is our supplier and our
 * customer; what we owe it on open bills is cleared against what it owes us
 * on open invoices, by an approved, numbered document. Never automatic;
 * reversed only through the corrections process (the Reverse button).
 */
export default function SetOffsIndex(props: Props) {
    const { t } = useTranslation();
    const { setOffs, canRaise, reversalReasons } = props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('set_off.title')} />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title={t('set_off.title')} description={t('set_off.intro')} />

                {canRaise && <RaiseForm {...props} />}

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
                    {setOffs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('set_off.none')}</p>
                    ) : (
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">{t('set_off.number')}</th>
                                    <th className="py-2 pr-3">{t('set_off.date')}</th>
                                    <th className="py-2 pr-3">{t('set_off.party')}</th>
                                    <th className="py-2 pr-3">{t('set_off.against')}</th>
                                    <th className="py-2 pr-3 text-right">{t('set_off.amount')}</th>
                                    <th className="py-2 pr-3">{t('set_off.status')}</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {setOffs.map((row) => {
                                    const reverse = row.actions.find((a) => a.action === 'reverse');
                                    const usable = reverse && (reverse.allowed || reverse.requestable);
                                    const base = `/records/accounting.set_off/${row.id}`;

                                    return (
                                        <tr key={row.id} className="border-b align-top">
                                            <td className="py-2 pr-3">
                                                <Link href={route('accounting.set-offs.show', row.id)} className="underline">
                                                    {row.number}
                                                </Link>
                                            </td>
                                            <td className="py-2 pr-3">{row.date}</td>
                                            <td className="py-2 pr-3">
                                                {row.supplier}
                                                {row.customer && row.customer !== row.supplier && (
                                                    <span className="text-muted-foreground block text-xs">{row.customer}</span>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3 text-xs">
                                                {[...row.bills, ...row.invoices].map((doc) => (
                                                    <span key={doc} className="block">
                                                        {doc}
                                                    </span>
                                                ))}
                                            </td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{row.amount}</td>
                                            <td className="py-2 pr-3">
                                                <StatusBadge row={row} />
                                                <span className="text-muted-foreground mt-1 block text-xs">{row.reason}</span>
                                            </td>
                                            <td className="py-2 text-right">
                                                {reverse &&
                                                    (usable ? (
                                                        <ActionDialog
                                                            action="reverse"
                                                            recordLabel={row.number}
                                                            previewUrl={`${base}/reverse/preview`}
                                                            submitUrl={`${base}/reverse`}
                                                            reasons={reversalReasons}
                                                            allowToday={reverse.allowed && reverse.date?.rule === 'original'}
                                                            trigger={
                                                                <Button size="sm" variant="outline">
                                                                    {t('set_off.reverse')}
                                                                </Button>
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground block max-w-56 text-xs">
                                                            {reverse.blockers[0]?.message}
                                                        </span>
                                                    ))}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
