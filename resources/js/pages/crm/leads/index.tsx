import { Chip } from '@/components/assets/assets-ui';
import { number } from '@/components/crm/lead-format';
import { LeadList } from '@/components/crm/lead-list';
import { type LeadDialog, type LeadFormOptions, LeadPopup } from '@/components/crm/lead-popup';
import { type Lead, type ValueLabel } from '@/components/crm/lead-types';
import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/overview' },
    { title: 'Leads', href: '/crm/leads' },
];

type Filters = {
    search: string;
    status: string;
    brand_id: string;
    assigned_to_user_id: string;
    lead_type: string;
    verification: string;
    district: string;
    show: string;
    follow_up: string;
    cold: string;
};

type ViewKey = 'working' | 'today' | 'unverified' | 'cold' | 'duplicates' | 'archived' | 'all';

/**
 * ⭐ The quick views (round 3, 16 Oct 2026). Each is a set of the list's own
 * filters: the four of `show`, `verification=unverified`, and the two new
 * ones, `follow_up=today` and `cold=1`.
 */
const VIEWS: { key: ViewKey; label: string; set: Pick<Filters, 'show' | 'verification' | 'follow_up' | 'cold'> }[] = [
    { key: 'working', label: 'Working list', set: { show: '', verification: '', follow_up: '', cold: '' } },
    { key: 'today', label: 'Follow-up today', set: { show: '', verification: '', follow_up: 'today', cold: '' } },
    { key: 'unverified', label: 'Not verified', set: { show: '', verification: 'unverified', follow_up: '', cold: '' } },
    { key: 'cold', label: 'Going cold', set: { show: '', verification: '', follow_up: '', cold: '1' } },
    { key: 'duplicates', label: 'Duplicates', set: { show: 'duplicates', verification: '', follow_up: '', cold: '' } },
    { key: 'archived', label: 'Archived', set: { show: 'archived', verification: '', follow_up: '', cold: '' } },
    { key: 'all', label: 'Everything', set: { show: 'all', verification: '', follow_up: '', cold: '' } },
];

function activeView(filters: Filters): ViewKey | null {
    const found = VIEWS.find(
        (view) =>
            view.set.show === filters.show &&
            view.set.verification === filters.verification &&
            view.set.follow_up === filters.follow_up &&
            view.set.cold === filters.cold,
    );

    return found?.key ?? null;
}

/**
 * The lead list. This page owns only the quick view, the filters and which
 * lead a popup is open for; the rows (LeadList), the More menu
 * (LeadActionsMenu) and the popups (LeadPopup) live in `components/crm/`.
 */
export default function LeadsIndex({
    leads,
    filters: initialFilters,
    views,
    today,
    statuses,
    can_manage,
    see_bin = false,
    ...options
}: {
    leads: Paginated<Lead>;
    filters: Filters;
    views: Record<ViewKey, number>;
    today: string;
    statuses: ValueLabel[];
    can_manage: boolean;
    /** ⭐ Corrections wave 3: the Owner's link to deleted leads. */
    see_bin?: boolean;
} & LeadFormOptions) {
    const { t } = useTranslation();
    const { filters, setFilter, setMany } = useFilters('/crm/leads', initialFilters);
    // Clear empties the bar and keeps the quick view.
    const resetFilters = () => setMany({ search: '', status: '', brand_id: '', assigned_to_user_id: '', lead_type: '', district: '' });
    const [dialog, setDialog] = useState<LeadDialog>(null);

    // "+ Lead" on the Overview opens the add popup here (?add=1).
    useEffect(() => {
        if (can_manage && new URLSearchParams(window.location.search).has('add')) {
            setDialog({ kind: 'add' });
        }
    }, [can_manage]);

    const current = activeView(filters);
    const barActive = ['search', 'status', 'brand_id', 'assigned_to_user_id', 'lead_type', 'district'].some(
        (key) => filters[key as keyof Filters] !== '',
    );

    const can = {
        manage: can_manage,
        convert: options.can_convert,
        convertSupplier: options.can_convert_supplier,
        verify: options.can_verify,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leads" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">Shops, distributors, suppliers and people we are working to win.</p>
                    <div className="flex flex-wrap items-center gap-3">
                        {see_bin && (
                            <Link href="/settings/recycle-bin?module=crm.lead" className="text-muted-foreground text-xs underline">
                                {t('corr.crm.recently_deleted')}
                            </Link>
                        )}
                        {can_manage && (
                            <Button type="button" size="sm" onClick={() => setDialog({ kind: 'add' })}>
                                Add a lead
                            </Button>
                        )}
                    </div>
                </div>

                <div role="group" aria-label="Quick views" className="flex flex-wrap gap-1.5">
                    {VIEWS.map((view) => (
                        <Chip key={view.key} active={current === view.key} count={number(views[view.key] ?? 0)} onClick={() => setMany(view.set)}>
                            {view.label}
                        </Chip>
                    ))}
                </div>

                <FilterBar showClear={barActive} onClear={() => resetFilters()}>
                    <Input
                        placeholder="Search name, phone, market"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="w-full sm:max-w-xs"
                    />
                    <SearchSelect fit aria-label="Type" value={filters.lead_type} onChange={(e) => setFilter('lead_type', e.target.value)}>
                        <option value="">All types</option>
                        {options.lead_types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect fit aria-label="District" value={filters.district} onChange={(e) => setFilter('district', e.target.value)}>
                        <option value="">All districts</option>
                        {options.districts.map((d) => (
                            <option key={d} value={d}>
                                {d}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect fit aria-label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All statuses</option>
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect fit aria-label="Brand" value={filters.brand_id} onChange={(e) => setFilter('brand_id', e.target.value)}>
                        <option value="">All brands</option>
                        <option value="none">No brand</option>
                        {options.brands.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </SearchSelect>
                    {/* ⚠ Only the people this reader may see leads for. For an officer
                        that is himself, so the filter would be noise. */}
                    {options.agents.length > 1 && (
                        <SearchSelect
                            fit
                            aria-label="Held by"
                            value={filters.assigned_to_user_id}
                            onChange={(e) => setFilter('assigned_to_user_id', e.target.value)}
                        >
                            <option value="">Held by anyone</option>
                            {options.can_assign && <option value="none">Unassigned</option>}
                            {options.agents.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                    )}
                </FilterBar>

                <LeadList
                    leads={leads.data}
                    today={today}
                    can={can}
                    onEdit={(lead) => setDialog({ kind: 'edit', lead })}
                    onConvert={(lead) => setDialog({ kind: 'convert', lead, customerId: null })}
                />

                <Pagination meta={leads} />
            </div>

            <LeadPopup dialog={dialog} options={options} onChange={setDialog} />
        </AppLayout>
    );
}
