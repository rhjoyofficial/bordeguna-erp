<?php

namespace App\Modules\Crm\Actions;

use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\WarehouseOwnership;
use App\Modules\Crm\Models\DistributorLeadProfile;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadCoverageArea;
use App\Modules\Crm\Models\RetailerLeadProfile;
use App\Modules\Crm\Models\SupplierLeadProfile;
use App\Support\BdGeo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a lead's type adds to it: the retailer's, distributor's or supplier's
 * profile (CRM lead slice 2c). Every field is one of Joy's interview answers;
 * see the migration `add_type_profiles_to_leads` for which.
 *
 * ⚠ The rules and the writer live together so the screen, a future importer and
 * a future API cannot disagree about what a retailer profile may hold.
 *
 * ⚠ A profile is written only when the request carries at least one of its
 * fields. A capture path that knows nothing about profiles (a web form, an
 * import) must not blank a profile somebody filled in by hand.
 */
class SaveLeadProfile
{
    public const RETAILER_FIELDS = ['shop_type_id', 'current_supplier', 'wants_credit', 'competitor_brand_ids'];

    public const DISTRIBUTOR_FIELDS = [
        'business_nature', 'other_companies', 'warehouse_count', 'warehouse_ownership',
        'vehicle_count', 'sales_staff_count', 'coverage',
    ];

    public const SUPPLIER_FIELDS = ['supplier_type_id', 'supply_categories', 'origin_district'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(int $businessId): array
    {
        $lookup = fn (CrmLookupList $list) => Rule::exists('crm_lookups', 'id')
            ->where('business_id', $businessId)->where('list', $list->value);

        return [
            'shop_type_id' => ['nullable', 'integer', $lookup(CrmLookupList::ShopType)],
            'current_supplier' => ['nullable', 'string', 'max:160'],
            // 'yes', 'no', or empty for "not asked" - see RetailerLeadProfile.
            'wants_credit' => ['nullable', Rule::in(['yes', 'no'])],
            'competitor_brand_ids' => ['nullable', 'array', 'max:50'],
            'competitor_brand_ids.*' => ['integer', 'distinct', $lookup(CrmLookupList::CompetitorBrand)],

            'business_nature' => ['nullable', 'string', 'max:160'],
            'other_companies' => ['nullable', 'string', 'max:2000'],
            'warehouse_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'warehouse_ownership' => ['nullable', Rule::enum(WarehouseOwnership::class)],
            'vehicle_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'sales_staff_count' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'coverage' => ['nullable', 'array', 'max:200'],
            'coverage.*.district' => ['required', 'string', 'max:60'],
            'coverage.*.thana' => ['required', 'string', 'max:80'],

            'supplier_type_id' => ['nullable', 'integer', $lookup(CrmLookupList::SupplierType)],
            'supply_categories' => ['nullable', 'string', 'max:255'],
            'origin_district' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  validated request data
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, array $input): void
    {
        match ($lead->lead_type) {
            LeadType::Retailer => $this->retailer($lead, $input),
            LeadType::Distributor => $this->distributor($lead, $input),
            LeadType::Supplier => $this->supplier($lead, $input),
            LeadType::Customer => null,
        };
    }

    /** @param array<string, mixed> $input */
    private function retailer(Lead $lead, array $input): void
    {
        if (! $this->carriesAny($input, self::RETAILER_FIELDS)) {
            return;
        }

        DB::transaction(function () use ($lead, $input) {
            RetailerLeadProfile::updateOrCreate(['lead_id' => $lead->id], [
                'business_id' => $lead->business_id,
                'shop_type_id' => $input['shop_type_id'] ?? null,
                'current_supplier' => $input['current_supplier'] ?? null,
                'wants_credit' => match ($input['wants_credit'] ?? null) {
                    'yes' => true,
                    'no' => false,
                    default => null,
                },
            ]);

            $lead->competitorBrands()->sync(
                collect($input['competitor_brand_ids'] ?? [])
                    ->mapWithKeys(fn ($id) => [(int) $id => ['business_id' => $lead->business_id]])
                    ->all(),
            );
        });
    }

    /** @param array<string, mixed> $input */
    private function distributor(Lead $lead, array $input): void
    {
        if (! $this->carriesAny($input, self::DISTRIBUTOR_FIELDS)) {
            return;
        }

        // ⚠ Placed on the map BEFORE anything is written, so a bad thana refuses
        // the whole save instead of leaving half a coverage list behind.
        $coverage = collect($input['coverage'] ?? [])
            ->map(fn (array $row, int $i) => $this->placeCoverage($row, $i))
            ->unique(fn (array $row) => $row['district'].'|'.$row['thana'])
            ->values();

        DB::transaction(function () use ($lead, $input, $coverage) {
            DistributorLeadProfile::updateOrCreate(['lead_id' => $lead->id], [
                'business_id' => $lead->business_id,
                'business_nature' => $input['business_nature'] ?? null,
                'other_companies' => $input['other_companies'] ?? null,
                'warehouse_count' => $input['warehouse_count'] ?? null,
                'warehouse_ownership' => $input['warehouse_ownership'] ?? null,
                'vehicle_count' => $input['vehicle_count'] ?? null,
                'sales_staff_count' => $input['sales_staff_count'] ?? null,
            ]);

            // ⚠ Replaced as a whole. The list is the distributor's current claim,
            // not evidence of anything, so there is no history to keep.
            LeadCoverageArea::where('lead_id', $lead->id)->delete();

            foreach ($coverage as $row) {
                LeadCoverageArea::create($row + ['business_id' => $lead->business_id, 'lead_id' => $lead->id]);
            }
        });
    }

    /** @param array<string, mixed> $input */
    private function supplier(Lead $lead, array $input): void
    {
        if (! $this->carriesAny($input, self::SUPPLIER_FIELDS)) {
            return;
        }

        $origin = $input['origin_district'] ?? null;

        if (filled($origin) && BdGeo::districts() !== []) {
            $origin = BdGeo::districtNamed($origin)
                ?? throw ValidationException::withMessages(['origin_district' => 'Pick the district from the list.']);
        }

        SupplierLeadProfile::updateOrCreate(['lead_id' => $lead->id], [
            'business_id' => $lead->business_id,
            'supplier_type_id' => $input['supplier_type_id'] ?? null,
            'supply_categories' => $input['supply_categories'] ?? null,
            'origin_district' => $origin,
        ]);
    }

    /**
     * @param  array{district:string, thana:string}  $row
     * @return array{district:string, thana:string}
     *
     * @throws ValidationException
     */
    private function placeCoverage(array $row, int $index): array
    {
        if (BdGeo::districts() === []) {
            return ['district' => $row['district'], 'thana' => $row['thana']];
        }

        $district = BdGeo::districtNamed($row['district'])
            ?? throw ValidationException::withMessages(["coverage.{$index}.district" => 'Pick the district from the list.']);
        $thana = BdGeo::thanaNamed($district, $row['thana'])
            ?? throw ValidationException::withMessages(["coverage.{$index}.thana" => "Pick a thana of {$district} from the list."]);

        return ['district' => $district, 'thana' => $thana];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     */
    private function carriesAny(array $input, array $fields): bool
    {
        return collect($fields)->contains(fn (string $field) => array_key_exists($field, $input));
    }
}
