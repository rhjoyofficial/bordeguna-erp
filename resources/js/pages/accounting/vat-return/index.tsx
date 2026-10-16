import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Row {
    date: string;
    kind: string;
    number: string;
    party: string | null;
    value: string;
    tax: string;
    claimed: string | null;
    total: string;
    url: string | null;
}

interface Figures {
    regime: string;
    regime_label: string;
    sales: Row[];
    purchases: Row[];
    output_tax: string;
    input_tax: string;
    document_output: string;
    document_input: string;
    agrees: boolean;
    turnover: string;
    turnover_tax_rate: string;
    turnover_tax: string;
    credit_brought_forward: string;
    net_payable: string;
    credit_carried_forward: string;
}

interface Filed {
    return_number: string;
    filed_at: string | null;
    filed_by: string | null;
    output_tax: string;
    input_tax: string;
    net_payable: string;
    credit_carried_forward: string;
    journal_id: number | null;
}

interface Props {
    period: string;
    period_label: string;
    figures: Figures;
    filed: Filed | null;
    can_file: boolean;
    history: { return_number: string; period: string; period_label: string; net_payable: string; credit_carried_forward: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'VAT return', href: '/accounting/vat-return' },
];

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Register({ title, rows, withClaimed }: { title: string; rows: Row[]; withClaimed: boolean }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
            <h2 className="text-base font-semibold">{title}</h2>
            <div className="mt-3 overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left text-xs">
                            <th className="py-2 pr-3 font-medium">Date</th>
                            <th className="py-2 pr-3 font-medium">Document</th>
                            <th className="py-2 pr-3 font-medium">Number</th>
                            <th className="py-2 pr-3 font-medium">{withClaimed ? 'Supplier' : 'Customer'}</th>
                            <th className="py-2 pr-3 text-right font-medium">Value excl. VAT</th>
                            <th className="py-2 pr-3 text-right font-medium">VAT</th>
                            {withClaimed && <th className="py-2 pr-3 text-right font-medium">Claimed</th>}
                            <th className="py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={withClaimed ? 8 : 7} className="text-muted-foreground py-4 text-center text-sm">
                                    Nothing in this period.
                                </td>
                            </tr>
                        )}
                        {rows.map((row, index) => (
                            <tr key={`${row.number}-${row.kind}-${index}`} className="border-b last:border-0">
                                <td className="py-1.5 pr-3 whitespace-nowrap">{row.date}</td>
                                <td className="py-1.5 pr-3 whitespace-nowrap">{row.kind}</td>
                                <td className="py-1.5 pr-3 whitespace-nowrap">
                                    {row.url ? (
                                        <Link href={row.url} className="underline underline-offset-2">
                                            {row.number}
                                        </Link>
                                    ) : (
                                        row.number
                                    )}
                                </td>
                                <td className="py-1.5 pr-3">{row.party ?? '-'}</td>
                                <td className="py-1.5 pr-3 text-right tabular-nums">{money(row.value)}</td>
                                <td className="py-1.5 pr-3 text-right tabular-nums">{money(row.tax)}</td>
                                {withClaimed && <td className="py-1.5 pr-3 text-right tabular-nums">{money(row.claimed ?? '0')}</td>}
                                <td className="py-1.5 text-right tabular-nums">{money(row.total)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function VatReturn({ period, period_label, figures, filed, can_file, history }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const registered = figures.regime !== 'not_registered';
    const turnoverTax = figures.regime === 'turnover_tax';

    const lines: [string, string][] = turnoverTax
        ? [
              ['Sales in the period (turnover)', figures.turnover],
              [`Turnover tax at ${figures.turnover_tax_rate}%`, figures.turnover_tax],
          ]
        : [
              ['Output VAT (on sales)', figures.output_tax],
              ['Input VAT claimed (on purchases)', figures.input_tax],
          ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="VAT return" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-base font-semibold">{period_label}</p>
                            <Badge variant="outline">{figures.regime_label}</Badge>
                            {filed && <Badge>Filed {filed.return_number}</Badge>}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <Input
                            type="month"
                            className="w-auto"
                            value={period}
                            onChange={(event) => router.get('/accounting/vat-return', { period: event.target.value }, { preserveScroll: true })}
                        />
                        <Button variant="outline" asChild>
                            <a href={`/accounting/vat-return/export?period=${period}`}>Export</a>
                        </Button>
                        <Button variant="outline" onClick={() => window.print()}>
                            Print
                        </Button>
                    </div>
                </div>

                {!registered && (
                    <div className="bg-muted/40 rounded-xl border p-4 text-sm">
                        This business is not registered for VAT or turnover tax, so no VAT is charged and there is nothing to file. The registers
                        below still list the month&apos;s sales and purchases. The switch is in Accounting, VAT settings.
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                    <h2 className="text-base font-semibold">The month in one place</h2>
                    <dl className="mt-3 grid max-w-xl gap-1 text-sm">
                        {lines.map(([label, value]) => (
                            <div key={label} className="flex justify-between">
                                <dt className="text-muted-foreground">{label}</dt>
                                <dd className="tabular-nums">{money(value)}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">Credit brought forward from earlier months</dt>
                            <dd className="tabular-nums">({money(figures.credit_brought_forward)})</dd>
                        </div>
                        <div className="mt-1 flex justify-between border-t pt-2 font-semibold">
                            <dt>Payable for {period_label}</dt>
                            <dd className="tabular-nums">{money(figures.net_payable)}</dd>
                        </div>
                        {Number(figures.credit_carried_forward) > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Credit carried forward (not paid out)</dt>
                                <dd className="tabular-nums">{money(figures.credit_carried_forward)}</dd>
                            </div>
                        )}
                    </dl>

                    {!turnoverTax && registered && (
                        <p className={`mt-3 text-xs ${figures.agrees ? 'text-muted-foreground' : 'text-destructive font-medium'}`}>
                            {figures.agrees
                                ? 'The documents and the ledger agree on these figures.'
                                : `The documents and the ledger DISAGREE. Documents say output ${money(figures.document_output)} and input ${money(figures.document_input)}; the ledger says output ${money(figures.output_tax)} and input ${money(figures.input_tax)}. A posting to a VAT account exists that no document explains. Find it in the Day book before filing.`}
                        </p>
                    )}

                    {filed && (
                        <p className="text-muted-foreground mt-3 text-xs">
                            Filed on {filed.filed_at} by {filed.filed_by ?? 'unknown'}: output {money(filed.output_tax)}, input{' '}
                            {money(filed.input_tax)}, payable {money(filed.net_payable)}.
                        </p>
                    )}

                    <InputError message={errors.period} className="mt-3" />

                    {can_file && (
                        <div className="mt-4 print:hidden">
                            <ConfirmDialog
                                trigger={<Button>File the return for {period_label}</Button>}
                                title={`File the VAT return for ${period_label}`}
                                description={`This freezes the figures for ${period_label} and posts them to VAT Settlement on the last day of the month. It cannot be filed twice.`}
                                confirmLabel="File the return"
                                onConfirm={() => router.post('/accounting/vat-return', { period }, { preserveScroll: true })}
                            />
                        </div>
                    )}
                </div>

                <Register title="Sales register" rows={figures.sales} withClaimed={false} />
                <Register title="Purchase register" rows={figures.purchases} withClaimed />

                {history.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4 print:hidden">
                        <h2 className="text-base font-semibold">Returns filed</h2>
                        <ul className="mt-2 grid gap-1 text-sm">
                            {history.map((item) => (
                                <li key={item.return_number} className="flex flex-wrap justify-between gap-2">
                                    <Link href={`/accounting/vat-return?period=${item.period}`} className="underline underline-offset-2">
                                        {item.return_number} · {item.period_label}
                                    </Link>
                                    <span className="text-muted-foreground tabular-nums">
                                        payable {money(item.net_payable)}
                                        {Number(item.credit_carried_forward) > 0 && ` · credit forward ${money(item.credit_carried_forward)}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
