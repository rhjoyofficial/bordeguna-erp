import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Domain {
    id: number;
    hostname: string;
    is_primary: boolean;
}

interface Store {
    id: number;
    key: string;
    name: string;
    order_prefix: string;
    brand_id: number | null;
    brand_name: string | null;
    /* ⚠ An id now, not a customer-type string. Null is RETAIL, which is a
       real answer rather than a missing one. */
    price_group_id: number | null;
    price_group_name: string;
    seo_title: string | null;
    seo_description: string | null;
    is_indexable: boolean;
    availability_policy: string;
    theme_key: string | null;
    theme_name: string | null;
    status: string;
    status_label: string;
    can_go_live: boolean;
    referral_cookie_name: string | null;
    webhook_signature_header: string | null;
    coupon_storage_key: string | null;
    features: Record<string, boolean>;
    derived: { referral_cookie: string; webhook_header: string; coupon_key: string };
    domains: Domain[];
}

interface Option {
    value: string;
    label: string;
}

/**
 * ⚠ A price group is identified by its row id, not by a code string. `Option`
 * above stays as it is because the theme and availability pickers are genuinely
 * keyed by string, and widening it for everybody would hide the difference.
 */
interface PriceGroupOption {
    value: number;
    label: string;
}

interface ThemeOption extends Option {
    description: string;
}

/**
 * One store's settings, its domains, and the switch that puts it in front of
 * the public.
 *
 * ⭐ The derived identifiers are SHOWN rather than hidden. "What will this store
 * call its own cookie" is the question whose wrong answer had bionic.garden
 * reporting into LaLaDia's pixel, and it should be answerable before the store
 * is live rather than from a browser inspector afterwards.
 */
interface CompatibilityRow {
    page_id: number;
    page_title: string;
    page_path: string;
    page_published: boolean;
    section_position: number;
    section_label: string;
    identity: string;
    reason: string;
}

interface ThemeReport {
    theme_key: string;
    theme_name: string;
    can_switch: boolean;
    blocking: CompatibilityRow[];
    downgraded: CompatibilityRow[];
    draft_warnings: CompatibilityRow[];
    already_broken: CompatibilityRow[];
    counts: { pages: number; sections: number; blocking: number; downgraded: number; draft_warnings: number };
}

export default function StoreEdit({
    store,
    brands,
    priceGroups,
    availabilityPolicies,
    themes,
    themeReports,
}: {
    store: Store;
    brands: { id: number; name: string }[];
    priceGroups: PriceGroupOption[];
    availabilityPolicies: Option[];
    themes: ThemeOption[];
    themeReports: ThemeReport[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Websites', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
    ];

    const settings = useForm({
        name: store.name,
        brand_id: store.brand_id ? String(store.brand_id) : '',
        price_group_id: store.price_group_id,
        seo_title: store.seo_title ?? '',
        seo_description: store.seo_description ?? '',
        availability_policy: store.availability_policy,
        theme_key: store.theme_key ?? '',
        referral_cookie_name: store.referral_cookie_name ?? '',
        webhook_signature_header: store.webhook_signature_header ?? '',
        coupon_storage_key: store.coupon_storage_key ?? '',
    });

    // Typed explicitly: without it useForm infers `false` as a literal type
    // and the checkbox cannot ever set it back to true.
    const domain = useForm<{ hostname: string; is_primary: boolean }>({ hostname: '', is_primary: false });

    const saveSettings: FormEventHandler = (e) => {
        e.preventDefault();
        settings.put(route('commerce.stores.update', store.id), { preserveScroll: true });
    };

    const addDomain: FormEventHandler = (e) => {
        e.preventDefault();
        domain.post(route('commerce.stores.domains.store', store.id), {
            preserveScroll: true,
            onSuccess: () => domain.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={store.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-medium">{store.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            Key <code>{store.key}</code> · prefix <code>{store.order_prefix}</code> · both permanent
                            {store.theme_name && (
                                <>
                                    {' '}
                                    · theme <strong>{store.theme_name}</strong>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        {/* ⚠ A route nobody can find is not shipped. The catalogue
                            screen is reached from the shop it belongs to, which is
                            where somebody is standing when they think about what it
                            sells. */}
                        <Button variant="outline" asChild>
                            <Link href={`/commerce/stores/${store.id}/catalogue`}>Catalogue</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/commerce/stores/${store.id}/combos`}>Bundles</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/commerce/stores/${store.id}/coupons`}>Coupons</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/commerce/stores/${store.id}/shipping`}>Shipping</Link>
                        </Button>
                        <Badge variant={store.status === 'live' ? 'default' : 'secondary'}>{store.status_label}</Badge>
                        {/* ⚠ Said out loud. A shop that is live but invisible to
                            search engines is a normal, deliberate state during a
                            cutover, and somebody looking at it should not have to
                            guess which of the two it is in. */}
                        <Badge variant={store.is_indexable ? 'default' : 'outline'}>{store.is_indexable ? 'Indexable' : 'Hidden from search'}</Badge>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(`/commerce/stores/${store.id}/indexing`, { is_indexable: !store.is_indexable }, { preserveScroll: true })
                            }
                        >
                            {store.is_indexable ? 'Hide from search engines' : 'Allow search engines'}
                        </Button>
                        {store.status === 'live' ? (
                            <Button
                                variant="outline"
                                onClick={() => router.post(route('commerce.stores.unpublish', store.id), {}, { preserveScroll: true })}
                            >
                                Take offline
                            </Button>
                        ) : (
                            <Button
                                disabled={!store.can_go_live}
                                onClick={() => router.post(route('commerce.stores.publish', store.id), {}, { preserveScroll: true })}
                            >
                                Take live
                            </Button>
                        )}
                    </div>
                </div>

                {!store.can_go_live && store.status !== 'live' && (
                    <div className="border-sidebar-border/70 text-muted-foreground rounded-xl border p-4 text-sm">
                        This store cannot go live yet. It needs a theme and a primary domain, or there would be nothing to render.
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <form onSubmit={saveSettings} className="border-sidebar-border/70 flex flex-col gap-4 rounded-xl border p-4">
                        <h2 className="font-medium">Settings</h2>

                        <div className="grid gap-2">
                            <Label htmlFor="name" required>
                                Name
                            </Label>
                            <Input id="name" value={settings.data.name} onChange={(e) => settings.setData('name', e.target.value)} />
                            <InputError message={settings.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="brand_id">Brand</Label>
                            <Select value={settings.data.brand_id} onValueChange={(v) => settings.setData('brand_id', v)}>
                                <SelectTrigger id="brand_id">
                                    <SelectValue placeholder="No single brand (multi-brand store)" />
                                </SelectTrigger>
                                <SelectContent>
                                    {brands.map((brand) => (
                                        <SelectItem key={brand.id} value={String(brand.id)}>
                                            {brand.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={settings.errors.brand_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="price_group">Price group</Label>
                            <Select
                                value={settings.data.price_group_id === null ? 'default' : String(settings.data.price_group_id)}
                                onValueChange={(v) => settings.setData('price_group_id', v === 'default' ? null : Number(v))}
                            >
                                <SelectTrigger id="price_group">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {/* ⚠ "Retail (default)" is a real choice, not an
                                        empty one: a store with no group is priced as
                                        RETAIL by the selling-price resolver, and the
                                        picker says so rather than showing a blank
                                        somebody would read as unfinished setup. */}
                                    <SelectItem value="default">Retail (default)</SelectItem>
                                    {priceGroups.map((option) => (
                                        <SelectItem key={option.value} value={String(option.value)}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={settings.errors.price_group_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="availability_policy" required>
                                Availability
                            </Label>
                            <Select value={settings.data.availability_policy} onValueChange={(v) => settings.setData('availability_policy', v)}>
                                <SelectTrigger id="availability_policy">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {availabilityPolicies.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={settings.errors.availability_policy} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="theme_key">Theme</Label>
                            <Select value={settings.data.theme_key} onValueChange={(v) => settings.setData('theme_key', v)}>
                                <SelectTrigger id="theme_key">
                                    <SelectValue placeholder="No theme chosen yet" />
                                </SelectTrigger>
                                <SelectContent>
                                    {themes.map((theme) => (
                                        <SelectItem key={theme.value} value={theme.value}>
                                            {theme.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                A theme decides how the store looks, never what it says. The store cannot go live without one, because there would be
                                nothing to render.
                            </p>
                            <InputError message={settings.errors.theme_key} />

                            {/* ⭐ What a switch would cost, BEFORE it is made. An
                                unsupported section does not error when it renders,
                                it simply is not drawn, so this panel is the only
                                place the cost is visible while it can still be
                                avoided. */}
                            {themeReports.map((report) => (
                                <ThemeSwitchReport key={report.theme_key} report={report} />
                            ))}
                        </div>

                        <div className="border-sidebar-border/70 mt-2 flex flex-col gap-3 rounded-lg border p-3">
                            <div>
                                <h3 className="text-sm font-medium">Identifiers</h3>
                                <p className="text-muted-foreground text-xs">
                                    Leave blank and this store uses its own name, derived from the key. Fill one in only to keep a legacy value that
                                    live cookies or existing webhook subscribers already carry.
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="referral_cookie_name">Referral cookie</Label>
                                <Input
                                    id="referral_cookie_name"
                                    value={settings.data.referral_cookie_name}
                                    onChange={(e) => settings.setData('referral_cookie_name', e.target.value)}
                                    placeholder={store.derived.referral_cookie}
                                />
                                <InputError message={settings.errors.referral_cookie_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="webhook_signature_header">Webhook signature header</Label>
                                <Input
                                    id="webhook_signature_header"
                                    value={settings.data.webhook_signature_header}
                                    onChange={(e) => settings.setData('webhook_signature_header', e.target.value)}
                                    placeholder={store.derived.webhook_header}
                                />
                                <InputError message={settings.errors.webhook_signature_header} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="coupon_storage_key">Coupon storage key</Label>
                                <Input
                                    id="coupon_storage_key"
                                    value={settings.data.coupon_storage_key}
                                    onChange={(e) => settings.setData('coupon_storage_key', e.target.value)}
                                    placeholder={store.derived.coupon_key}
                                />
                                <InputError message={settings.errors.coupon_storage_key} />
                            </div>
                        </div>

                        <Button type="submit" disabled={settings.processing}>
                            Save settings
                        </Button>
                    </form>

                    <div className="border-sidebar-border/70 flex flex-col gap-4 rounded-xl border p-4">
                        <div>
                            <h2 className="font-medium">Domains</h2>
                            <p className="text-muted-foreground text-xs">
                                One primary hostname serves the store; the others redirect to it. A hostname belongs to one store across the whole
                                group.
                            </p>
                        </div>

                        <div className="flex flex-col gap-2">
                            {store.domains.length === 0 && <p className="text-muted-foreground text-sm">No domains yet.</p>}
                            {store.domains.map((d) => (
                                <div key={d.id} className="border-sidebar-border/70 flex items-center justify-between rounded-lg border p-2">
                                    <span className="text-sm">
                                        {d.hostname}
                                        {d.is_primary && (
                                            <Badge variant="secondary" className="ml-2">
                                                Primary
                                            </Badge>
                                        )}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(route('commerce.stores.domains.destroy', [store.id, d.id]), { preserveScroll: true })
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            ))}
                        </div>

                        <form onSubmit={addDomain} className="flex flex-col gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="hostname">Hostname</Label>
                                <Input
                                    id="hostname"
                                    value={domain.data.hostname}
                                    onChange={(e) => domain.setData('hostname', e.target.value)}
                                    placeholder="bionic.garden"
                                />
                                <InputError message={domain.errors.hostname} />
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={domain.data.is_primary}
                                    onChange={(e) => domain.setData('is_primary', e.target.checked)}
                                />
                                Make this the primary hostname
                            </label>

                            <Button type="submit" variant="outline" disabled={domain.processing}>
                                Add domain
                            </Button>
                        </form>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * What switching to this theme would do to the pages this store already has.
 *
 * ⛔ The important half is the REFUSAL, and it names pages rather than counting
 * them. "3 sections would go dark" tells nobody which three, and the person
 * reading this is about to decide whether to take pages down.
 *
 * ⚠ A fallback is a downgrade, not a failure: the words stay on the page and
 * the look changes. It is listed rather than waved through, because somebody
 * should know which pages will not look the same afterwards.
 */
function ThemeSwitchReport({ report }: { report: ThemeReport }) {
    const nothingToSay =
        report.blocking.length === 0 && report.downgraded.length === 0 && report.draft_warnings.length === 0 && report.already_broken.length === 0;

    if (nothingToSay) {
        return (
            <div className="text-muted-foreground rounded-md border border-dashed p-3 text-xs">
                <strong>{report.theme_name}</strong> can render all {report.counts.sections} sections on this store.
            </div>
        );
    }

    return (
        <div className={`rounded-md border p-3 text-xs ${report.can_switch ? '' : 'border-destructive/40 bg-destructive/5'}`}>
            <p className="text-sm font-medium">
                {report.theme_name}
                {report.can_switch ? (
                    <span className="text-muted-foreground font-normal"> can be switched to</span>
                ) : (
                    <span className="text-destructive font-normal"> cannot be switched to yet</span>
                )}
            </p>

            <Rows
                title={`${report.blocking.length} published ${report.blocking.length === 1 ? 'section' : 'sections'} would go blank`}
                rows={report.blocking}
                tone="destructive"
            />
            <Rows title={`${report.downgraded.length} would be drawn a different way`} rows={report.downgraded} tone="muted" />
            <Rows title={`${report.draft_warnings.length} on unpublished pages would go blank`} rows={report.draft_warnings} tone="muted" />
            <Rows title={`${report.already_broken.length} already broken, whichever theme you choose`} rows={report.already_broken} tone="muted" />
        </div>
    );
}

function Rows({ title, rows, tone }: { title: string; rows: CompatibilityRow[]; tone: 'destructive' | 'muted' }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="mt-2">
            <p className={tone === 'destructive' ? 'text-destructive font-medium' : 'text-muted-foreground font-medium'}>{title}</p>
            <ul className="text-muted-foreground mt-1 list-disc space-y-0.5 pl-4">
                {rows.map((row, index) => (
                    <li key={`${row.page_id}-${row.section_position}-${index}`}>
                        <span className="font-medium">{row.page_title}</span> <span className="font-mono">{row.page_path}</span> &middot; section{' '}
                        {row.section_position} ({row.section_label}) &middot; {row.reason}
                    </li>
                ))}
            </ul>
        </div>
    );
}
