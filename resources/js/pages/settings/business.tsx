import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
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
    currency: string;
    timezone: string;
    fiscal_year_start_month: number;
}

interface BusinessProps {
    business: BusinessProfile;
    logo_url: string | null;
    currencies: string[];
    timezones: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Business', href: '/settings/business' }];

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

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
                    <img src={logoUrl} alt="Business logo" className="border-sidebar-border/70 dark:border-sidebar-border h-16 w-16 rounded-md border object-contain" />
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex h-16 w-16 items-center justify-center rounded-md border text-xs text-muted-foreground">
                        No logo
                    </div>
                )}
                <div className="flex gap-2">
                    <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
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
            <p className="text-xs text-muted-foreground">PNG, JPG or WEBP, up to 2 MB.</p>
            <InputError message={error} />
        </div>
    );
}

export default function Business({ business, logo_url, currencies, timezones }: BusinessProps) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: business.name,
        legal_name: business.legal_name ?? '',
        address: business.address ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        currency: business.currency,
        timezone: business.timezone,
        fiscal_year_start_month: business.fiscal_year_start_month,
    });

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
                        <div className="grid gap-2">
                            <Label htmlFor="name">Display name</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="legal_name">Legal name</Label>
                            <Input
                                id="legal_name"
                                value={data.legal_name}
                                onChange={(e) => setData('legal_name', e.target.value)}
                                placeholder="Registered legal entity name (optional)"
                            />
                            <InputError message={errors.legal_name} />
                        </div>

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

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="phone">Phone</Label>
                                <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                                <InputError message={errors.phone} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                <InputError message={errors.email} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="currency">Currency</Label>
                                <select id="currency" className={selectClass} value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                    {currencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.currency} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Timezone</Label>
                                <select id="timezone" className={selectClass} value={data.timezone} onChange={(e) => setData('timezone', e.target.value)}>
                                    {timezones.map((timezone) => (
                                        <option key={timezone} value={timezone}>
                                            {timezone}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.timezone} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fiscal_year_start_month">Fiscal year starts</Label>
                                <select
                                    id="fiscal_year_start_month"
                                    className={selectClass}
                                    value={data.fiscal_year_start_month}
                                    onChange={(e) => setData('fiscal_year_start_month', Number(e.target.value))}
                                >
                                    {MONTHS.map((month, index) => (
                                        <option key={month} value={index + 1}>
                                            {month}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.fiscal_year_start_month} />
                            </div>
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
                                <p className="text-sm text-muted-foreground">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
