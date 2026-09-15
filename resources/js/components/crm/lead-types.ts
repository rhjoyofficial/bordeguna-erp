// Shapes the lead screen and its parts share. Mirrors LeadController::index().

export interface Lead {
    id: number;
    lead_type: string;
    lead_type_label: string;
    name: string | null;
    contact_name: string | null;
    phone: string | null;
    secondary_phone: string | null;
    email: string | null;
    address: string | null;
    district: string | null;
    thana: string | null;
    area: string | null;
    market: string | null;
    landmark: string | null;
    is_verified: boolean;
    verified_by: string | null;
    verified_on: string | null;
    is_archived: boolean;
    created_by: string | null;
    status: string;
    status_label: string;
    brand_id: number | null;
    brand: string | null;
    assigned_to_user_id: number | null;
    assigned_to: string | null;
    qualification_note: string | null;
    lost_reason_id: number | null;
    note: string | null;
    converted_customer: string | null;
    duplicate_of_lead_id: number | null;
    duplicate_of: string | null;
    created_at: string | null;
}

export interface Option {
    id: number;
    name: string;
    code?: string;
    lead_source_id?: number;
}

export interface CustomerOption {
    id: number;
    code: string | null;
    name: string;
    phone: string | null;
}

export interface LeadTypeOption {
    value: string;
    label: string;
    is_shop: boolean;
}

export interface ValueLabel {
    value: string;
    label: string;
}

export interface DuplicateMatch {
    leads: { id: number; name: string | null; phone: string | null; status: string }[];
    // ⚠ Leads with this number that the reader may not see: the holder's name
    // and nothing else (build plan D4).
    held_elsewhere: { held_by: string | null }[];
    customers: { id: number; name: string; code: string | null; phone: string | null; type: string }[];
}

/**
 * ⭐ For a shop-shaped lead, `name` IS the shop or the firm (design doc, agreed
 * item 1) - so the field says so, and nobody types the proprietor's name there
 * and the shop's name nowhere.
 */
export function nameLabelFor(leadType: string): string {
    switch (leadType) {
        case 'retailer':
            return 'Shop name';
        case 'distributor':
        case 'supplier':
            return 'Firm name';
        default:
            return 'Name';
    }
}

export function contactLabelFor(leadType: string): string {
    return leadType === 'retailer' || leadType === 'distributor' ? 'Proprietor' : 'Contact person';
}

/** Market first: it is how a shop is actually found; the thana is how it is counted. */
export function placeLine(lead: Pick<Lead, 'market' | 'area' | 'thana' | 'district'>): string {
    return [lead.market, lead.area, lead.thana, lead.district].filter(Boolean).join(', ');
}
