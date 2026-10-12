import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Rate {
    id: number;
    rate: string;
    effective_from: string;
    note: string | null;
}

interface TaxClass {
    id: number;
    code: string;
    name: string;
    is_turnover: boolean;
    fixed_zero: boolean;
    current_rate: string | null;
    rates: Rate[];
}

interface Regime {
    value: string;
    label: string;
    help: string;
}

interface Settings {
    regime: string;
    vat_enabled: boolean;
    applies_today: boolean;
    vat_effective_from: string | null;
    vat_bin: string | null;
    prices_include_tax: boolean;
    delivery_tax_class_id: number | null;
}

interface Props {
    settings: Settings;
    regimes: Regime[];
    classes: TaxClass[];
    documents: { taxed_invoices: number; taxed_bills: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'VAT settings', href: '/accounting/vat-settings' },
];

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-fit min-w-56 self-start rounded-md border px-3 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function VatSettings({ settings, regimes, classes, documents }: Props) {
    const form = useForm({
        regime: settings.regime,
        vat_effective_from: settings.vat_effective_from ?? '',
        vat_bin: settings.vat_bin ?? '',
        prices_include_tax: settings.prices_include_tax,
        delivery_tax_class_id: settings.delivery_tax_class_id ? String(settings.delivery_tax_class_id) : '',
    });

    const rateForm = useForm({
        tax_class_id: '',
        rate: '',
        effective_from: '',
        note: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put('/accounting/vat-settings', { preserveScroll: true });
    };

    const submitRate: FormEventHandler = (event) => {
        event.preventDefault();
        rateForm.post('/accounting/vat-settings/rates', {
            preserveScroll: true,
            onSuccess: () => rateForm.reset('rate', 'effective_from', 'note'),
        });
    };

    const registered = form.data.regime !== 'not_registered';
    const chosen = regimes.find((regime) => regime.value === form.data.regime);
    const rateable = classes.filter((taxClass) => !taxClass.fixed_zero);
    const vatClasses = classes.filter((taxClass) => !taxClass.is_turnover);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="VAT settings" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold">VAT settings</h1>
                        {settings.applies_today ? (
                            <Badge>VAT is being charged</Badge>
                        ) : settings.regime === 'turnover_tax' ? (
                            <Badge variant="secondary">Turnover tax</Badge>
                        ) : (
                            <Badge variant="outline">VAT is off</Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {documents.taxed_invoices} invoice(s) and {documents.taxed_bills} bill(s) carry VAT so far.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-5 rounded-xl border p-4"
                >
                    <div className="flex flex-col gap-2">
                        <Label required>Registration</Label>
                        <div className="flex flex-col gap-2">
                            {regimes.map((regime) => (
                                <label key={regime.value} className="flex cursor-pointer items-start gap-3 text-sm">
                                    <input
                                        type="radio"
                                        name="regime"
                                        className="mt-1"
                                        value={regime.value}
                                        checked={form.data.regime === regime.value}
                                        onChange={() => form.setData('regime', regime.value)}
                                    />
                                    <span>
                                        <span className="font-medium">{regime.label}</span>
                                        <span className="text-muted-foreground block text-xs">{regime.help}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.regime} />
                    </div>

                    {registered && (
                        <div className="flex flex-wrap gap-4">
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="vat_effective_from" required>
                                    Starts from
                                </Label>
                                <Input
                                    id="vat_effective_from"
                                    type="date"
                                    className="w-auto"
                                    value={form.data.vat_effective_from}
                                    onChange={(event) => form.setData('vat_effective_from', event.target.value)}
                                />
                                <InputError message={form.errors.vat_effective_from} />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="vat_bin" required>
                                    BIN
                                </Label>
                                <Input
                                    id="vat_bin"
                                    className="w-56"
                                    placeholder="000000000-0000"
                                    value={form.data.vat_bin}
                                    onChange={(event) => form.setData('vat_bin', event.target.value)}
                                />
                                <InputError message={form.errors.vat_bin} />
                            </div>
                        </div>
                    )}

                    <div className="flex flex-col gap-2">
                        <Label required>When nobody says otherwise, a selling price</Label>
                        <div className="flex flex-col gap-2 text-sm">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="radio"
                                    className="mt-1"
                                    checked={form.data.prices_include_tax}
                                    onChange={() => form.setData('prices_include_tax', true)}
                                />
                                <span>
                                    <span className="font-medium">already includes VAT</span>
                                </span>
                            </label>
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="radio"
                                    className="mt-1"
                                    checked={!form.data.prices_include_tax}
                                    onChange={() => form.setData('prices_include_tax', false)}
                                />
                                <span>
                                    <span className="font-medium">has VAT added on top</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div className="flex flex-col gap-1">
                        <Label htmlFor="delivery_tax_class_id">A delivery charge is taxed as</Label>
                        <select
                            id="delivery_tax_class_id"
                            className={selectClass}
                            value={form.data.delivery_tax_class_id}
                            onChange={(event) => form.setData('delivery_tax_class_id', event.target.value)}
                        >
                            <option value="">Standard (the default)</option>
                            {vatClasses.map((taxClass) => (
                                <option key={taxClass.id} value={taxClass.id}>
                                    {taxClass.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.delivery_tax_class_id} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Save VAT settings
                        </Button>
                        {chosen && <span className="text-muted-foreground text-xs">{chosen.label}</span>}
                    </div>
                </form>

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
                    <h2 className="text-base font-semibold">Rates</h2>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs">
                                    <th className="py-2 pr-4 font-medium">Class</th>
                                    <th className="py-2 pr-4 font-medium">In force today</th>
                                    <th className="py-2 font-medium">History</th>
                                </tr>
                            </thead>
                            <tbody>
                                {classes.map((taxClass) => (
                                    <tr key={taxClass.id} className="border-b align-top last:border-0">
                                        <td className="py-2 pr-4 font-medium whitespace-nowrap">{taxClass.name}</td>
                                        <td className="py-2 pr-4 whitespace-nowrap">
                                            {taxClass.fixed_zero ? '0% (always)' : (taxClass.current_rate ?? 'Not set')}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {taxClass.rates.length === 0
                                                ? '-'
                                                : taxClass.rates.map((rate) => `${rate.rate} from ${rate.effective_from}`).join(' · ')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <form onSubmit={submitRate} className="mt-4 flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate_class" required>
                                Class
                            </Label>
                            <select
                                id="rate_class"
                                className={selectClass}
                                value={rateForm.data.tax_class_id}
                                onChange={(event) => rateForm.setData('tax_class_id', event.target.value)}
                            >
                                <option value="">Choose a class</option>
                                {rateable.map((taxClass) => (
                                    <option key={taxClass.id} value={taxClass.id}>
                                        {taxClass.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={rateForm.errors.tax_class_id} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate_value" required>
                                Rate %
                            </Label>
                            <Input
                                id="rate_value"
                                type="number"
                                step="0.0001"
                                min="0"
                                max="100"
                                className="w-36"
                                value={rateForm.data.rate}
                                onChange={(event) => rateForm.setData('rate', event.target.value)}
                            />
                            <InputError message={rateForm.errors.rate} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate_from" required>
                                Starts from
                            </Label>
                            <Input
                                id="rate_from"
                                type="date"
                                className="w-auto"
                                value={rateForm.data.effective_from}
                                onChange={(event) => rateForm.setData('effective_from', event.target.value)}
                            />
                            <InputError message={rateForm.errors.effective_from} />
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="rate_note">Note</Label>
                            <Input
                                id="rate_note"
                                className="w-64"
                                placeholder="SRO or the accountant's instruction"
                                value={rateForm.data.note}
                                onChange={(event) => rateForm.setData('note', event.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="outline" disabled={rateForm.processing}>
                            Add rate
                        </Button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
