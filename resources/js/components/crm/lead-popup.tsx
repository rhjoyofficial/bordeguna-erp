import { LeadConvertForm } from '@/components/crm/lead-convert-form';
import { LeadConvertSupplierForm } from '@/components/crm/lead-convert-supplier-form';
import { LeadForm } from '@/components/crm/lead-form';
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
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

/** What the add, edit and convert popups pick from: LeadFormOptions on the server. */
export interface LeadFormOptions {
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
}

export type LeadDialog = { kind: 'add' } | { kind: 'edit'; lead: Lead } | { kind: 'convert'; lead: Lead; customerId: number | null } | null;

/**
 * ⚠ Add, edit and convert open in ONE popup (Joy, 14 Oct 2026: "add edit will
 * from the pop up"), the same popup on the lead list and on the lead page
 * (round 3), so the two can never ask for different things.
 */
export function LeadPopup({
    dialog,
    options,
    onChange,
}: {
    dialog: LeadDialog;
    /** Null while the lead page is still fetching them. */
    options: LeadFormOptions | null;
    onChange: (dialog: LeadDialog) => void;
}) {
    const close = () => onChange(null);

    const title =
        dialog === null
            ? ''
            : dialog.kind === 'add'
              ? 'Add a lead'
              : dialog.kind === 'edit'
                ? `Edit ${dialog.lead.name ?? `lead #${dialog.lead.id}`}`
                : `Convert ${dialog.lead.name ?? `lead #${dialog.lead.id}`}`;

    return (
        <Dialog open={dialog !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl" aria-describedby={undefined}>
                {/* Each form draws its own heading; the title is for screen readers. */}
                <DialogTitle className="sr-only">{title}</DialogTitle>
                {options === null ? (
                    <p className="text-muted-foreground p-4 text-sm">Loading...</p>
                ) : dialog?.kind === 'convert' && dialog.lead.lead_type === 'supplier' ? (
                    // ⭐ Plan D12: a supplier lead becomes a supplier, never a customer.
                    <LeadConvertSupplierForm
                        key={`supplier-${dialog.lead.id}`}
                        inDialog
                        lead={dialog.lead}
                        suppliers={options.suppliers}
                        onDone={close}
                    />
                ) : dialog?.kind === 'convert' ? (
                    <LeadConvertForm
                        key={`${dialog.lead.id}-${dialog.customerId ?? 'new'}`}
                        inDialog
                        lead={dialog.lead}
                        existingCustomerId={dialog.customerId}
                        customers={options.customers}
                        customerTypes={options.customer_types}
                        priceGroups={options.price_groups}
                        creditTypes={options.credit_types}
                        onDone={close}
                    />
                ) : dialog ? (
                    <LeadForm
                        key={dialog.kind === 'edit' ? `edit-${dialog.lead.id}` : 'add'}
                        inDialog
                        editing={dialog.kind === 'edit' ? dialog.lead : null}
                        onDone={close}
                        onConvertOnto={(lead, customerId) => onChange({ kind: 'convert', lead, customerId })}
                        leadTypes={options.lead_types}
                        districts={options.districts}
                        selectableStatuses={options.selectable_statuses}
                        brands={options.brands}
                        sources={options.sources}
                        campaigns={options.campaigns}
                        lostReasons={options.lost_reasons}
                        agents={options.agents}
                        canAssign={options.can_assign}
                        canConvert={options.can_convert}
                        canVerify={options.can_verify}
                        lookups={options.lookups}
                        potentials={options.potentials}
                        importances={options.importances}
                        warehouseOwnerships={options.warehouse_ownerships}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
