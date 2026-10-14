import { LeadConvertForm } from '@/components/crm/lead-convert-form';
import { LeadConvertSupplierForm } from '@/components/crm/lead-convert-supplier-form';
import { LeadForm } from '@/components/crm/lead-form';
import { LeadList } from '@/components/crm/lead-list';
import {
    type CustomerOption,
    type Lead,
    type LeadTypeOption,
    type Lookups,
    type Option,
    type PriceGroupOption,
    type SupplierOption,
    type ValueLabel,
} from '@/components/crm/lead-types';
import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Leads', href: '/crm/leads' },
];

/**
 * The lead screen.
 *
 * ⚠ Split into parts on 15 Sep 2026 (CRM slice 2b) when it reached 834 lines
 * with a type, a location and a lifecycle still to add: the form, the
 * conversion panel and the list each live in `components/crm/`. This page owns
 * only which lead is being edited or converted, and the filters.
 */
export default function LeadsIndex({
    leads,
    filters: initialFilters,
    statuses,
    selectable_statuses,
    lead_types,
    districts,
    brands,
    sources,
    campaigns,
    lost_reasons,
    agents,
    can_assign,
    can_convert,
    can_convert_supplier,
    suppliers,
    can_verify,
    customers,
    customer_types,
    price_groups,
    credit_types,
    lookups,
    potentials,
    importances,
    warehouse_ownerships,
}: {
    leads: Paginated<Lead>;
    filters: {
        search: string;
        status: string;
        brand_id: string;
        assigned_to_user_id: string;
        lead_type: string;
        verification: string;
        district: string;
        show: string;
    };
    statuses: ValueLabel[];
    selectable_statuses: ValueLabel[];
    lead_types: LeadTypeOption[];
    districts: string[];
    brands: Option[];
    sources: Option[];
    campaigns: Option[];
    lost_reasons: Option[];
    agents: Option[];
    can_assign: boolean;
    can_convert: boolean;
    can_convert_supplier: boolean;
    suppliers: SupplierOption[];
    can_verify: boolean;
    customers: CustomerOption[];
    customer_types: ValueLabel[];
    price_groups: PriceGroupOption[];
    credit_types: string[];
    lookups: Lookups;
    potentials: ValueLabel[];
    importances: ValueLabel[];
    warehouse_ownerships: ValueLabel[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/crm/leads', initialFilters);

    // ⚠ Add, edit and convert open in ONE popup over the list (Joy, 14 Oct 2026:
    // "add edit will from the pop up, not like current above the list"). It also
    // ends the 15 Sep phone-width problem: an officer no longer scrolls past
    // twenty fields to reach his own shops, on a phone or a desk.
    const [dialog, setDialog] = useState<
        { kind: 'add' } | { kind: 'edit'; lead: Lead } | { kind: 'convert'; lead: Lead; customerId: number | null } | null
    >(null);
    const close = () => setDialog(null);

    const dialogTitle =
        dialog === null
            ? ''
            : dialog.kind === 'add'
              ? 'Add a lead'
              : dialog.kind === 'edit'
                ? `Edit ${dialog.lead.name ?? `lead #${dialog.lead.id}`}`
                : `Convert ${dialog.lead.name ?? `lead #${dialog.lead.id}`}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leads" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-lg font-semibold">Leads</h1>
                    <Button type="button" onClick={() => setDialog({ kind: 'add' })}>
                        Add a lead
                    </Button>
                </div>

                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search name, phone, market"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="w-full sm:max-w-xs"
                    />
                    <SearchSelect value={filters.show} onChange={(e) => setFilter('show', e.target.value)}>
                        <option value="">Working list</option>
                        <option value="duplicates">Duplicates</option>
                        <option value="archived">Archived</option>
                        <option value="all">Everything</option>
                    </SearchSelect>
                    <SearchSelect value={filters.lead_type} onChange={(e) => setFilter('lead_type', e.target.value)}>
                        <option value="">All types</option>
                        {lead_types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.verification} onChange={(e) => setFilter('verification', e.target.value)}>
                        <option value="">Verified or not</option>
                        <option value="unverified">Unverified</option>
                        <option value="verified">Verified</option>
                    </SearchSelect>
                    <SearchSelect value={filters.district} onChange={(e) => setFilter('district', e.target.value)}>
                        <option value="">All districts</option>
                        {districts.map((d) => (
                            <option key={d} value={d}>
                                {d}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All statuses</option>
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.brand_id} onChange={(e) => setFilter('brand_id', e.target.value)}>
                        <option value="">All brands</option>
                        <option value="none">No brand</option>
                        {brands.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </SearchSelect>
                    {/* ⚠ Only the people this reader may see leads for. For an officer
                        that is himself, so the filter would be noise. */}
                    {agents.length > 1 && (
                        <SearchSelect value={filters.assigned_to_user_id} onChange={(e) => setFilter('assigned_to_user_id', e.target.value)}>
                            <option value="">Held by anyone</option>
                            {can_assign && <option value="none">Unassigned</option>}
                            {agents.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                    )}
                </FilterBar>

                <LeadList
                    leads={leads.data}
                    canConvert={can_convert}
                    canConvertSupplier={can_convert_supplier}
                    canVerify={can_verify}
                    onEdit={(lead) => setDialog({ kind: 'edit', lead })}
                    onConvert={(lead) => setDialog({ kind: 'convert', lead, customerId: null })}
                />

                <Pagination meta={leads} />
            </div>

            <Dialog open={dialog !== null} onOpenChange={(open) => !open && close()}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl" aria-describedby={undefined}>
                    {/* Each form draws its own heading; the title is for screen readers. */}
                    <DialogTitle className="sr-only">{dialogTitle}</DialogTitle>
                    {dialog?.kind === 'convert' && dialog.lead.lead_type === 'supplier' ? (
                        // ⭐ Plan D12: a supplier lead becomes a supplier, never a customer.
                        <LeadConvertSupplierForm
                            key={`supplier-${dialog.lead.id}`}
                            inDialog
                            lead={dialog.lead}
                            suppliers={suppliers}
                            onDone={close}
                        />
                    ) : dialog?.kind === 'convert' ? (
                        <LeadConvertForm
                            key={`${dialog.lead.id}-${dialog.customerId ?? 'new'}`}
                            inDialog
                            lead={dialog.lead}
                            existingCustomerId={dialog.customerId}
                            customers={customers}
                            customerTypes={customer_types}
                            priceGroups={price_groups}
                            creditTypes={credit_types}
                            onDone={close}
                        />
                    ) : dialog ? (
                        <LeadForm
                            key={dialog.kind === 'edit' ? `edit-${dialog.lead.id}` : 'add'}
                            inDialog
                            editing={dialog.kind === 'edit' ? dialog.lead : null}
                            onDone={close}
                            onConvertOnto={(lead, customerId) => setDialog({ kind: 'convert', lead, customerId })}
                            leadTypes={lead_types}
                            districts={districts}
                            selectableStatuses={selectable_statuses}
                            brands={brands}
                            sources={sources}
                            campaigns={campaigns}
                            lostReasons={lost_reasons}
                            agents={agents}
                            canAssign={can_assign}
                            canConvert={can_convert}
                            canVerify={can_verify}
                            lookups={lookups}
                            potentials={potentials}
                            importances={importances}
                            warehouseOwnerships={warehouse_ownerships}
                        />
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
