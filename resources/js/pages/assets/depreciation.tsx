import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

interface Charge {
    id: number;
    period: string;
    charged_on: string | null;
    asset: string;
    amount: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Fixed assets', href: '/assets' },
    { title: 'Depreciation', href: '/assets/depreciation' },
];

export default function Depreciation({
    charges,
    canRun,
    defaultYear,
    defaultMonth,
    hasLedgerMapping,
    depreciableAssets,
}: {
    charges: Paginated<Charge>;
    canRun: boolean;
    defaultYear: number;
    defaultMonth: number;
    hasLedgerMapping: boolean;
    depreciableAssets: number;
}) {
    const { data, setData, post, errors, processing } = useForm({
        year: String(defaultYear),
        month: String(defaultMonth),
    });

    const pageErrors = usePage().props.errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('assets.depreciation.store'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Depreciation" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Depreciation" />

                {/*
                  ⚠⚠ Not a cosmetic warning. With no mapping the engine still writes
                  the charge rows and posts NOTHING, deliberately: the register is
                  useful before the ledger is configured. But it is silent about it,
                  and "I ran depreciation and the P&L did not move" is exactly the
                  kind of thing nobody notices until the year end.
                */}
                {!hasLedgerMapping && (
                    <div className="border-destructive/50 bg-destructive/10 rounded-md border p-3 text-sm">
                        <p className="font-medium">Depreciation is not mapped to the ledger.</p>
                        <p className="text-muted-foreground">
                            Charges will be recorded against each asset, but nothing will reach the accounts and the profit and loss will not show the
                            cost. Seed the asset account mappings first.
                        </p>
                    </div>
                )}

                {canRun && (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="month" required>
                                Month
                            </Label>
                            <SearchSelect fit id="month" value={data.month} onChange={(e) => setData('month', e.target.value)}>
                                {MONTHS.map((name, index) => (
                                    <option key={name} value={index + 1}>
                                        {name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.month} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="year" required>
                                Year
                            </Label>
                            <Input
                                id="year"
                                value={data.year}
                                onChange={(e) => setData('year', e.target.value)}
                                className="w-24 text-right"
                                inputMode="numeric"
                            />
                            <InputError message={errors.year} />
                        </div>

                        <Button disabled={processing}>Run depreciation</Button>

                        <span className="text-muted-foreground text-sm">
                            {depreciableAssets} asset{depreciableAssets === 1 ? '' : 's'} still to write down.
                        </span>

                        {/* ⚠ `period` is not a form FIELD - it is the action's own
                            refusal ("August 2026 has not finished yet"), so it arrives
                            in the shared error bag rather than on useForm's typed
                            errors. Without reading it here the run would simply do
                            nothing with no explanation on screen. */}
                        <InputError message={pageErrors.period} />
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Period</th>
                                <th className="p-3">Charged on</th>
                                <th className="p-3">Asset</th>
                                <th className="p-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {charges.data.map((charge) => (
                                <tr key={charge.id} className="border-t">
                                    <td className="p-3 font-medium">{charge.period}</td>
                                    <td className="p-3">{charge.charged_on ?? '-'}</td>
                                    <td className="p-3">{charge.asset}</td>
                                    <td className="p-3 text-right tabular-nums">{charge.amount}</td>
                                </tr>
                            ))}
                            {charges.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground p-6 text-center">
                                        Nothing has been depreciated yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={charges} />
            </div>
        </AppLayout>
    );
}
