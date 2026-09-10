import { Field, FormRow } from '@/components/form/field';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

interface BusinessProfile {
    id: number;
    name: string;
    legal_name: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    document_font: string | null;
    currency: string;
    timezone: string;
    fiscal_year_start_month: number;
    payroll_cycle_start_day: number;
    wfh_monthly_cap: number;
}

interface FontChoice {
    value: string;
    label: string;
    category: string;
    note: string;
    pdf: boolean;
    stack: string;
}

interface BusinessProps {
    business: BusinessProfile;
    logo_url: string | null;
    currencies: string[];
    timezones: string[];
    fonts: FontChoice[];
    default_font: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business', href: '/settings/business' }];

/* A bilingual sample: Latin, Bangla and digits in one line. */
const PREVIEW = 'Bor de Guna - বর দে গুনা - 1,234.56';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function LogoSection({ logoUrl }: { logoUrl: string | null }) {
    const fileRef = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);

    const upload = (file?: File) => {
        if (!file) return;
        setError(undefined);
        setBusy(true);
        const form = new FormData();
        form.append('logo', file);
        router.post(route('business.logo.store'), form, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => setError(errors.logo),
            onFinish: () => {
                setBusy(false);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const remove = () => router.delete(route('business.logo.destroy'), { preserveScroll: true });

    return (
        <div className="grid gap-2">
            <Label>Logo</Label>
            <div className="flex items-center gap-4">
                {logoUrl ? (
                    <img
                        src={logoUrl}
                        alt="Business logo"
                        className="border-sidebar-border/70 dark:border-sidebar-border h-16 w-16 rounded-md border object-contain"
                    />
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground flex h-16 w-16 items-center justify-center rounded-md border text-xs">
                        No logo
                    </div>
                )}
                <div className="flex gap-2">
                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                    <Button type="button" variant="outline" onClick={() => fileRef.current?.click()} disabled={busy}>
                        {logoUrl ? 'Replace' : 'Upload'}
                    </Button>
                    {logoUrl && (
                        <Button type="button" variant="ghost" onClick={remove}>
                            Remove
                        </Button>
                    )}
                </div>
            </div>
            <p className="text-muted-foreground text-xs">PNG, JPG or WEBP, up to 2 MB.</p>
            <InputError message={error} />
        </div>
    );
}

export default function Business({ business, logo_url, currencies, timezones, fonts, default_font }: BusinessProps) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: business.name,
        legal_name: business.legal_name ?? '',
        address: business.address ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        website: business.website ?? '',
        document_font: business.document_font ?? default_font,
        currency: business.currency,
        timezone: business.timezone,
        fiscal_year_start_month: business.fiscal_year_start_month,
        payroll_cycle_start_day: business.payroll_cycle_start_day,
        wfh_monthly_cap: business.wfh_monthly_cap,
    });

    const chosenFont = fonts.find((font) => font.value === data.document_font);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('business.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Business settings" />

            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall
                        title="Business"
                        description="Your company profile for this business. Currency and timezone are used by payroll and reporting later on."
                    />

                    <LogoSection logoUrl={logo_url} />

                    <form onSubmit={submit} className="space-y-6">
                        {/* Each box as wide as what it holds (30 Aug 2026 audit). */}
                        <FormRow>
                            <Field label="Display name" htmlFor="name" size="lg" error={errors.name} required>
                                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </Field>
                            <Field label="Legal name" htmlFor="legal_name" size="grow" error={errors.legal_name}>
                                <Input
                                    id="legal_name"
                                    value={data.legal_name}
                                    onChange={(e) => setData('legal_name', e.target.value)}
                                    placeholder="Registered legal entity name (optional)"
                                />
                            </Field>
                        </FormRow>

                        <div className="grid gap-2">
                            <Label htmlFor="address">Address</Label>
                            <textarea
                                id="address"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring min-h-20 rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                            />
                            <InputError message={errors.address} />
                        </div>

                        <FormRow>
                            <Field label="Phone" htmlFor="phone" size="md" error={errors.phone}>
                                <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                            </Field>
                            <Field label="Email" htmlFor="email" size="lg" error={errors.email}>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            </Field>
                            <Field label="Website" htmlFor="website" size="lg" error={errors.website}>
                                <Input
                                    id="website"
                                    placeholder="www.bordeguna.com"
                                    value={data.website}
                                    onChange={(e) => setData('website', e.target.value)}
                                />
                            </Field>
                        </FormRow>

                        {/* The face official documents are set in. Only this one
                            is a setting: the sidebar and the headings stay in
                            the interface font until Joy says otherwise. */}
                        <div className="grid gap-2">
                            <Label htmlFor="document_font">Font for official documents</Label>
                            <SearchSelect
                                fit
                                id="document_font"
                                value={data.document_font}
                                onChange={(e) => setData('document_font', e.target.value)}
                            >
                                {fonts.map((font) => (
                                    <option key={font.value} value={font.value}>
                                        {font.label} ({font.category})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.document_font} />

                            {chosenFont && (
                                <>
                                    {/* ⚠ Both scripts and a number in ONE line. That is
                                        what these documents actually carry, and it is the
                                        only way to see whether a single face handles all
                                        of it rather than the browser quietly borrowing a
                                        second font for the Bangla. */}
                                    <p className="rounded-md border p-3 text-lg" style={{ fontFamily: chosenFont.stack }}>
                                        {PREVIEW}
                                    </p>
                                    <p className="text-muted-foreground max-w-2xl text-xs">{chosenFont.note}</p>
                                </>
                            )}

                            {chosenFont && !chosenFont.pdf && (
                                <p className="max-w-2xl rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-xs">
                                    Used everywhere a document is printed from the browser, but <strong>not in the payslip PDF</strong>, which is
                                    built by a renderer that can only read plain TrueType from disk. Payslips keep the default face rather than
                                    silently falling back to one that cannot write Bangla at all.
                                </p>
                            )}

                            {data.document_font !== default_font && (
                                <p className="max-w-2xl rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-xs">
                                    Office Circular BdG-HQ-OD-26071201 names <strong>Tiro Bangla</strong> as the official font for documents leaving
                                    the organisation. Changing this is a decision to depart from that circular.
                                </p>
                            )}
                        </div>

                        <FormRow>
                            <Field label="Currency" htmlFor="currency" error={errors.currency} required>
                                <SearchSelect id="currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                    {currencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label="Timezone" htmlFor="timezone" error={errors.timezone} required>
                                <SearchSelect id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)}>
                                    {timezones.map((timezone) => (
                                        <option key={timezone} value={timezone}>
                                            {timezone}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label="Fiscal year starts" htmlFor="fiscal_year_start_month" error={errors.fiscal_year_start_month} required>
                                <SearchSelect
                                    id="fiscal_year_start_month"
                                    value={data.fiscal_year_start_month}
                                    onChange={(e) => setData('fiscal_year_start_month', Number(e.target.value))}
                                >
                                    {MONTHS.map((month, index) => (
                                        <option key={month} value={index + 1}>
                                            {month}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        </FormRow>

                        <div className="grid gap-2">
                            <Label htmlFor="payroll_cycle_start_day" required>
                                Payroll month starts on day
                            </Label>
                            <SearchSelect
                                fit
                                id="payroll_cycle_start_day"
                                value={data.payroll_cycle_start_day}
                                onChange={(e) => setData('payroll_cycle_start_day', Number(e.target.value))}
                            >
                                {Array.from({ length: 28 }, (_, i) => i + 1).map((day) => (
                                    <option key={day} value={day}>
                                        {day}
                                    </option>
                                ))}
                            </SearchSelect>
                            <p className="text-muted-foreground text-xs">
                                With 24, the month named August runs 24 July to 23 August. This covers payroll, attendance and HR reports only. Bills,
                                invoices and the accounts keep their own dates.
                            </p>
                            <InputError message={errors.payroll_cycle_start_day} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="wfh_monthly_cap" required>
                                Work from home, days a month
                            </Label>
                            <SearchSelect
                                fit
                                id="wfh_monthly_cap"
                                value={data.wfh_monthly_cap}
                                onChange={(e) => setData('wfh_monthly_cap', Number(e.target.value))}
                            >
                                {Array.from({ length: 32 }, (_, i) => i).map((days) => (
                                    <option key={days} value={days}>
                                        {days}
                                    </option>
                                ))}
                            </SearchSelect>
                            <p className="text-muted-foreground text-xs">
                                The most any one person may work from home in a calendar month. Requests still awaiting approval count against it.
                                Zero means work from home is not offered here. People at home still clock in and out as usual.
                            </p>
                            <InputError message={errors.wfh_monthly_cap} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>
                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-muted-foreground text-sm">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
