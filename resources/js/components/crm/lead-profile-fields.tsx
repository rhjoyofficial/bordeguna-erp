import { type Lead, type LookupEntry, type Lookups, type ValueLabel } from '@/components/crm/lead-types';
import { BdPlaceFields } from '@/components/form/bd-place-fields';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * What a lead's type adds to it (CRM lead slice 2c). Every field is one of Joy's
 * interview answers, recorded in CRM-LEAD-PROFILES-DESIGN-2026-09-14.md:
 * retailer 4, 5, 6, 7, 9 · distributor 5, 6, 12, 17 · supplier 14.
 *
 * ⚠ Only the CURRENT type's fields are posted (see `profilePayload`). An officer
 * who typed a coverage row, then changed the type to Retailer, must not be
 * refused a save over a field he can no longer see.
 */
export const EMPTY_PROFILE = {
    potential: '',
    importance: '',
    shop_type_id: '',
    current_supplier: '',
    wants_credit: '',
    competitor_brand_ids: [] as string[],
    business_nature: '',
    other_companies: '',
    warehouse_count: '',
    warehouse_ownership: '',
    vehicle_count: '',
    sales_staff_count: '',
    coverage: [] as { district: string; thana: string }[],
    supplier_type_id: '',
    supply_categories: '',
    origin_district: '',
};

export type ProfileData = typeof EMPTY_PROFILE;

const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

export function profileFromLead(lead: Lead): ProfileData {
    const p = lead.profile ?? {};

    return {
        potential: text(lead.potential),
        importance: text(lead.importance),
        shop_type_id: text(p.shop_type_id),
        current_supplier: text(p.current_supplier),
        wants_credit: text(p.wants_credit),
        competitor_brand_ids: (p.competitor_brand_ids ?? []).map(String),
        business_nature: text(p.business_nature),
        other_companies: text(p.other_companies),
        warehouse_count: text(p.warehouse_count),
        warehouse_ownership: text(p.warehouse_ownership),
        vehicle_count: text(p.vehicle_count),
        sales_staff_count: text(p.sales_staff_count),
        coverage: p.coverage ?? [],
        supplier_type_id: text(p.supplier_type_id),
        supply_categories: text(p.supply_categories),
        origin_district: text(p.origin_district),
    };
}

const FIELDS_BY_TYPE: Record<string, (keyof ProfileData)[]> = {
    customer: [],
    retailer: ['potential', 'importance', 'shop_type_id', 'current_supplier', 'wants_credit', 'competitor_brand_ids'],
    distributor: [
        'potential',
        'importance',
        'business_nature',
        'other_companies',
        'warehouse_count',
        'warehouse_ownership',
        'vehicle_count',
        'sales_staff_count',
        'coverage',
    ],
    supplier: ['supplier_type_id', 'supply_categories', 'origin_district'],
};

/** Drops every profile field that does not belong to `leadType`. */
export function profilePayload<T extends ProfileData>(leadType: string, data: T): T {
    const keep = new Set(FIELDS_BY_TYPE[leadType] ?? []);
    const out = { ...data } as Record<string, unknown>;

    (Object.keys(EMPTY_PROFILE) as (keyof ProfileData)[]).forEach((field) => {
        if (!keep.has(field)) {
            delete out[field];
        }
    });

    return out as T;
}

/** Active entries, plus any retired one this lead still carries so it is not silently blanked. */
function pickable(entries: LookupEntry[], selected: string[]): LookupEntry[] {
    return entries.filter((e) => e.is_active || selected.includes(String(e.id)));
}

export function LeadProfileFields({
    leadType,
    data,
    set,
    errors,
    lookups,
    potentials,
    importances,
    warehouseOwnerships,
    districts,
}: {
    leadType: string;
    data: ProfileData;
    set: <K extends keyof ProfileData>(field: K, value: ProfileData[K]) => void;
    errors: Record<string, string | undefined>;
    lookups: Lookups;
    potentials: ValueLabel[];
    importances: ValueLabel[];
    warehouseOwnerships: ValueLabel[];
    districts: string[];
}) {
    if (leadType === 'customer') {
        return null;
    }

    const rated = leadType === 'retailer' || leadType === 'distributor';

    return (
        <fieldset className="grid gap-4 border-t pt-4 sm:col-span-2 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-4">
            <legend className="text-sm font-medium">About this {leadType}</legend>

            {rated && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="potential">Potential (your estimate)</Label>
                        <SearchSelect id="potential" value={data.potential} onChange={(e) => set('potential', e.target.value)}>
                            <option value="">Not estimated</option>
                            {potentials.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.potential} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="importance">Importance</Label>
                        <SearchSelect id="importance" value={data.importance} onChange={(e) => set('importance', e.target.value)}>
                            <option value="">Unrated</option>
                            {importances.map((i) => (
                                <option key={i.value} value={i.value}>
                                    {i.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.importance} />
                    </div>
                </>
            )}

            {leadType === 'retailer' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="shop_type_id">Shop type</Label>
                        <SearchSelect id="shop_type_id" value={data.shop_type_id} onChange={(e) => set('shop_type_id', e.target.value)}>
                            <option value="">Not chosen</option>
                            {pickable(lookups.shop_type, [data.shop_type_id]).map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.shop_type_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wants_credit">Wants credit (baki)</Label>
                        <SearchSelect id="wants_credit" value={data.wants_credit} onChange={(e) => set('wants_credit', e.target.value)}>
                            <option value="">Not asked</option>
                            <option value="yes">Yes</option>
                            <option value="no">No, pays cash</option>
                        </SearchSelect>
                        <InputError message={errors.wants_credit} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="current_supplier">Who supplies them now (optional)</Label>
                        <Input id="current_supplier" value={data.current_supplier} onChange={(e) => set('current_supplier', e.target.value)} />
                        <InputError message={errors.current_supplier} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                        <span className="text-sm font-medium">Competitor brands on the shelf</span>
                        {pickable(lookups.competitor_brand, data.competitor_brand_ids).length === 0 ? (
                            <p className="text-muted-foreground text-sm">No competitor brands listed yet. They are added in CRM Settings.</p>
                        ) : (
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {pickable(lookups.competitor_brand, data.competitor_brand_ids).map((b) => {
                                    const id = String(b.id);
                                    const checked = data.competitor_brand_ids.includes(id);

                                    return (
                                        <label key={b.id} className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={checked}
                                                onChange={() =>
                                                    set(
                                                        'competitor_brand_ids',
                                                        checked
                                                            ? data.competitor_brand_ids.filter((x) => x !== id)
                                                            : [...data.competitor_brand_ids, id],
                                                    )
                                                }
                                            />
                                            {b.name}
                                        </label>
                                    );
                                })}
                            </div>
                        )}
                        <InputError message={errors.competitor_brand_ids} />
                    </div>
                </>
            )}

            {leadType === 'distributor' && (
                <>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="business_nature">Business nature (optional)</Label>
                        <Input id="business_nature" value={data.business_nature} onChange={(e) => set('business_nature', e.target.value)} />
                        <InputError message={errors.business_nature} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="other_companies">Other companies they distribute (optional)</Label>
                        <textarea
                            id="other_companies"
                            className="border-input bg-background min-h-16 rounded-md border px-3 py-2 text-sm"
                            value={data.other_companies}
                            onChange={(e) => set('other_companies', e.target.value)}
                        />
                        <InputError message={errors.other_companies} />
                    </div>
                    {(
                        [
                            ['warehouse_count', 'Warehouses'],
                            ['vehicle_count', 'Delivery vehicles'],
                            ['sales_staff_count', 'Sales staff'],
                        ] as const
                    ).map(([field, label]) => (
                        <div key={field} className="grid gap-2">
                            <Label htmlFor={field}>{label}</Label>
                            <Input
                                id={field}
                                type="number"
                                min={0}
                                inputMode="numeric"
                                value={data[field]}
                                onChange={(e) => set(field, e.target.value)}
                            />
                            <InputError message={errors[field]} />
                        </div>
                    ))}
                    <div className="grid gap-2">
                        <Label htmlFor="warehouse_ownership">Warehouses are</Label>
                        <SearchSelect
                            id="warehouse_ownership"
                            value={data.warehouse_ownership}
                            onChange={(e) => set('warehouse_ownership', e.target.value)}
                        >
                            <option value="">Not said</option>
                            {warehouseOwnerships.map((w) => (
                                <option key={w.value} value={w.value}>
                                    {w.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.warehouse_ownership} />
                    </div>

                    <div className="grid gap-3 sm:col-span-2 lg:col-span-4">
                        <span className="text-sm font-medium">Areas they say they cover</span>
                        {data.coverage.map((row, i) => (
                            <div key={i} className="grid items-start gap-3 rounded-md border p-3 sm:grid-cols-3">
                                <BdPlaceFields
                                    idPrefix={`coverage_${i}`}
                                    withArea={false}
                                    required
                                    districts={districts}
                                    value={{ district: row.district, thana: row.thana, area: '' }}
                                    errors={{ district: errors[`coverage.${i}.district`], thana: errors[`coverage.${i}.thana`] }}
                                    onChange={(place) =>
                                        set(
                                            'coverage',
                                            data.coverage.map((r, j) => (j === i ? { district: place.district, thana: place.thana } : r)),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="self-end"
                                    onClick={() =>
                                        set(
                                            'coverage',
                                            data.coverage.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            className="self-start"
                            onClick={() => set('coverage', [...data.coverage, { district: '', thana: '' }])}
                        >
                            Add an area
                        </Button>
                    </div>
                </>
            )}

            {leadType === 'supplier' && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_type_id">Supplier type</Label>
                        <SearchSelect id="supplier_type_id" value={data.supplier_type_id} onChange={(e) => set('supplier_type_id', e.target.value)}>
                            <option value="">Not chosen</option>
                            {pickable(lookups.supplier_type, [data.supplier_type_id]).map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.supplier_type_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="origin_district">Goods come from (district, optional)</Label>
                        <SearchSelect id="origin_district" value={data.origin_district} onChange={(e) => set('origin_district', e.target.value)}>
                            <option value="">Not said</option>
                            {districts.map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.origin_district} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="supply_categories">What they supply (optional)</Label>
                        <Input
                            id="supply_categories"
                            placeholder="Mustard seed, honey, glass jars"
                            value={data.supply_categories}
                            onChange={(e) => set('supply_categories', e.target.value)}
                        />
                        <InputError message={errors.supply_categories} />
                    </div>
                </>
            )}
        </fieldset>
    );
}
