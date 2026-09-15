<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmLookupSeeder;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Models\CrmLookup;
use App\Modules\Crm\Models\DistributorLeadProfile;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadCoverageArea;
use App\Modules\Crm\Models\RetailerLeadProfile;
use App\Modules\Crm\Models\SupplierLeadProfile;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2c: what a retailer, a distributor or a supplier adds to a lead.
 *
 * ⭐ Every field on trial here is one of Joy's interview answers (design doc,
 * 14-15 Sep 2026). The tests that matter most are the refusals: a list entry
 * from the wrong list or the wrong business, a coverage thana outside its
 * district that must take the whole save down with it, and a capture path that
 * knows nothing about profiles and must not blank one.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Profile Co', 'slug' => 'profile-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    (new CrmLookupSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create(['name' => 'Musa']);
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->officer = User::factory()->create(['name' => 'Rakib']);
    $this->officer->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->officer->assignRole('Sales Officer');

    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->lookup = fn (CrmLookupList $list, string $name) => CrmLookup::query()->ofList($list)->where('name', $name)->sole();
    $this->brand = fn (string $name) => CrmLookup::create(['business_id' => $this->business->id, 'list' => 'competitor_brand', 'name' => $name]);

    $this->base = fn (string $type, array $extra = []) => array_merge([
        'lead_type' => $type, 'name' => ucfirst($type).' One', 'phone' => '01711000'.random_int(100, 999),
        'status' => 'new', 'district' => 'Dhaka', 'thana' => 'Mirpur',
    ], $extra);

    $this->row = fn (User $user) => ($this->as)($user)->get('/crm/leads')->assertOk()->viewData('page')['props']['leads']['data'][0];
});

/* -------------------------------------------------------------------------
 | Retailer
 | ---------------------------------------------------------------------- */

it('records what a shop is, who supplies it, what it stocks and whether it wants credit', function () {
    $grocery = ($this->lookup)(CrmLookupList::ShopType, 'Grocery / mudi dokan');
    $pran = ($this->brand)('Pran');
    $radhuni = ($this->brand)('Radhuni');

    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', [
        'potential' => 'high', 'importance' => 'A',
        'shop_type_id' => $grocery->id, 'current_supplier' => 'Karim Traders', 'wants_credit' => 'yes',
        'competitor_brand_ids' => [$pran->id, $radhuni->id],
    ]))->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->sole();
    $profile = RetailerLeadProfile::sole();

    expect($lead->potential->value)->toBe('high')
        ->and($lead->importance->value)->toBe('A')
        ->and($profile->shop_type_id)->toBe($grocery->id)
        ->and($profile->wants_credit)->toBeTrue()
        ->and($lead->competitorBrands()->pluck('name')->sort()->values()->all())->toBe(['Pran', 'Radhuni']);

    expect(($this->row)($this->officer)['profile'])->toMatchArray([
        'shop_type' => 'Grocery / mudi dokan', 'current_supplier' => 'Karim Traders', 'wants_credit' => 'yes',
    ]);
});

it('keeps "not asked" apart from "no" on credit', function () {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['wants_credit' => '']))->assertSessionHasNoErrors();
    expect(RetailerLeadProfile::sole()->wants_credit)->toBeNull();

    $lead = Lead::withoutGlobalScopes()->sole();
    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ($this->base)('retailer', ['phone' => $lead->phone, 'wants_credit' => 'no']))
        ->assertSessionHasNoErrors();
    expect(RetailerLeadProfile::sole()->wants_credit)->toBeFalse();
});

it('replaces the competitor brands on an edit rather than piling them up', function () {
    $pran = ($this->brand)('Pran');
    $radhuni = ($this->brand)('Radhuni');

    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['competitor_brand_ids' => [$pran->id]]))->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->sole();

    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ($this->base)('retailer', ['phone' => $lead->phone, 'competitor_brand_ids' => [$radhuni->id]]))
        ->assertSessionHasNoErrors();

    expect($lead->competitorBrands()->pluck('name')->all())->toBe(['Radhuni']);
});

it('refuses a list entry from the wrong list or another business', function (string $field, Closure $entry) {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', [$field => $entry($this)]))->assertSessionHasErrors();

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'a supplier type as the shop type' => ['shop_type_id', fn ($t) => ($t->lookup)(CrmLookupList::SupplierType, 'Packaging supplier')->id],
    'a shop type as a competitor brand' => ['competitor_brand_ids', fn ($t) => [($t->lookup)(CrmLookupList::ShopType, 'Food / restaurant')->id]],
    'another business\'s shop type' => ['shop_type_id', function ($t) {
        $other = Business::create(['name' => 'Rival', 'slug' => 'rival-profile']);

        return CrmLookup::withoutGlobalScopes()->create(['business_id' => $other->id, 'list' => 'shop_type', 'name' => 'Theirs'])->id;
    }],
]);

/* -------------------------------------------------------------------------
 | Ratings belong to shops that are rated
 | ---------------------------------------------------------------------- */

it('never rates a consumer or a supplier, whatever the form sends', function (string $type) {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)($type, ['potential' => 'high', 'importance' => 'A']))->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->sole();

    expect($lead->potential)->toBeNull()->and($lead->importance)->toBeNull();
})->with(['customer', 'supplier']);

/* -------------------------------------------------------------------------
 | Distributor
 | ---------------------------------------------------------------------- */

it('records a distributor\'s claimed capacity and the areas it says it covers', function () {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('distributor', [
        'potential' => 'medium', 'business_nature' => 'FMCG distribution', 'other_companies' => 'Pran, ACI',
        'warehouse_count' => 2, 'warehouse_ownership' => 'rented', 'vehicle_count' => 3, 'sales_staff_count' => 6,
        'coverage' => [['district' => 'dhaka', 'thana' => 'Mirpur'], ['district' => 'Dhaka', 'thana' => 'Dhanmondi'], ['district' => 'Dhaka', 'thana' => 'mirpur']],
    ]))->assertSessionHasNoErrors();

    $profile = DistributorLeadProfile::sole();

    expect($profile->warehouse_count)->toBe(2)
        ->and($profile->warehouse_ownership->value)->toBe('rented')
        // ⚠ Canonical spelling, and the same thana typed twice is one row.
        ->and(LeadCoverageArea::orderBy('thana')->get()->map(fn ($a) => "{$a->district}/{$a->thana}")->all())->toBe(['Dhaka/Dhanmondi', 'Dhaka/Mirpur']);
});

it('refuses a coverage thana outside its district, and saves nothing at all', function () {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('distributor', [
        'coverage' => [['district' => 'Dhaka', 'thana' => 'Mirpur'], ['district' => 'Sylhet', 'thana' => 'Mirpur']],
    ]))->assertSessionHasErrors(['coverage.1.thana']);

    // ⚠⚠ The lead was written before the coverage was checked; the transaction
    // is what stops a profile-less lead surviving behind the error.
    expect(Lead::withoutGlobalScopes()->count())->toBe(0)
        ->and(DistributorLeadProfile::count())->toBe(0);
});

it('refuses a warehouse ownership that is not one of the three', function () {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('distributor', ['warehouse_ownership' => 'leased']))
        ->assertSessionHasErrors('warehouse_ownership');
});

/* -------------------------------------------------------------------------
 | Supplier
 | ---------------------------------------------------------------------- */

it('records a supplier\'s type, what it supplies and where the goods come from', function () {
    $producer = ($this->lookup)(CrmLookupList::SupplierType, 'Producer / primary source');

    ($this->as)($this->owner)->post('/crm/leads', ($this->base)('supplier', [
        'supplier_type_id' => $producer->id, 'supply_categories' => 'Mustard seed, honey', 'origin_district' => 'sirajganj',
    ]))->assertSessionHasNoErrors();

    expect(SupplierLeadProfile::sole()->only(['supplier_type_id', 'supply_categories', 'origin_district']))
        ->toBe(['supplier_type_id' => $producer->id, 'supply_categories' => 'Mustard seed, honey', 'origin_district' => 'Sirajganj']);
});

it('refuses an origin district that is not a district', function () {
    ($this->as)($this->owner)->post('/crm/leads', ($this->base)('supplier', ['origin_district' => 'Atlantis']))
        ->assertSessionHasErrors('origin_district');

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 | A profile survives what does not know about it
 | ---------------------------------------------------------------------- */

it('leaves a profile alone when an edit carries none of its fields', function () {
    $grocery = ($this->lookup)(CrmLookupList::ShopType, 'Grocery / mudi dokan');
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['shop_type_id' => $grocery->id, 'current_supplier' => 'Karim']))
        ->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->sole();

    // A capture path with no profile fields: an importer, a web form.
    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ($this->base)('retailer', ['phone' => $lead->phone, 'name' => 'Renamed']))
        ->assertSessionHasNoErrors();

    expect(RetailerLeadProfile::sole()->current_supplier)->toBe('Karim');
});

it('keeps the old profile when the type changes, and shows only the current one', function () {
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['current_supplier' => 'Karim']))->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->sole();

    ($this->as)($this->officer)->put("/crm/leads/{$lead->id}", ($this->base)('distributor', ['phone' => $lead->phone, 'vehicle_count' => 4]))
        ->assertSessionHasNoErrors();

    expect(RetailerLeadProfile::count())->toBe(1)
        ->and(($this->row)($this->officer)['profile'])->toHaveKey('vehicle_count', 4)
        ->and(($this->row)($this->officer)['profile'])->not->toHaveKey('current_supplier');
});

it('still names a retired shop type on the lead that carries it', function () {
    $grocery = ($this->lookup)(CrmLookupList::ShopType, 'Grocery / mudi dokan');
    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['shop_type_id' => $grocery->id]))->assertSessionHasNoErrors();

    $grocery->update(['is_active' => false]);

    expect(($this->row)($this->officer)['profile']['shop_type'])->toBe('Grocery / mudi dokan');
});

/* -------------------------------------------------------------------------
 | The lists themselves
 | ---------------------------------------------------------------------- */

it('starts the lists from Joy\'s own answers, and leaves competitor brands for him', function () {
    $names = fn (CrmLookupList $list) => CrmLookup::query()->ofList($list)->ordered()->pluck('name')->all();

    expect($names(CrmLookupList::ShopType))->toBe(['Grocery / mudi dokan', 'Super shop / departmental', 'Wholesale / arot', 'Pharmacy / health / organic shop', 'Food / restaurant'])
        ->and($names(CrmLookupList::SupplierType))->toBe(['Producer / primary source', 'Trader / wholesaler / arot', 'Manufacturer / processor', 'Packaging supplier'])
        ->and($names(CrmLookupList::CompetitorBrand))->toBe([]);
});

it('never re-seeds a list somebody has already edited', function () {
    ($this->lookup)(CrmLookupList::ShopType, 'Grocery / mudi dokan')->update(['name' => 'Mudi dokan']);
    ($this->lookup)(CrmLookupList::ShopType, 'Food / restaurant')->update(['is_active' => false]);

    (new CrmLookupSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    expect(CrmLookup::query()->ofList(CrmLookupList::ShopType)->count())->toBe(5)
        ->and(CrmLookup::query()->ofList(CrmLookupList::ShopType)->where('name', 'Grocery / mudi dokan')->exists())->toBeFalse()
        ->and(($this->lookup)(CrmLookupList::ShopType, 'Food / restaurant')->is_active)->toBeFalse();
});

it('is wired into the seeder a production deploy runs', function () {
    expect(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))->toContain('$this->call(CrmLookupSeeder::class);');
});

it('lets settings add, rename and retire entries, each unique only inside its own list', function () {
    ($this->as)($this->owner)->post('/crm/settings/lists/competitor_brand', ['name' => 'Pran'])->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post('/crm/settings/lists/competitor_brand', ['name' => 'Pran'])->assertSessionHasErrors('name');
    // The same words may be a shop type and a supplier type at once.
    ($this->as)($this->owner)->post('/crm/settings/lists/supplier_type', ['name' => 'Wholesale / arot'])->assertSessionHasNoErrors();

    $pran = ($this->lookup)(CrmLookupList::CompetitorBrand, 'Pran');
    ($this->as)($this->owner)->put("/crm/settings/lists/competitor_brand/{$pran->id}", ['name' => 'PRAN', 'is_active' => false])->assertSessionHasNoErrors();

    expect($pran->fresh()->only(['name', 'is_active']))->toBe(['name' => 'PRAN', 'is_active' => false]);
});

it('gives lost reasons a screen at last, and a lead can then be lost for one', function () {
    ($this->as)($this->owner)->post('/crm/settings/lists/lead_lost', ['name' => 'Price too high'])->assertSessionHasNoErrors();
    $reason = Reason::forContext('lead_lost')->sole();

    ($this->as)($this->officer)->post('/crm/leads', ($this->base)('retailer', ['status' => 'lost', 'lost_reason_id' => $reason->id]))
        ->assertSessionHasNoErrors();

    expect(Lead::withoutGlobalScopes()->sole()->lost_reason_id)->toBe($reason->id);
});

it('keeps the lists out of an officer\'s hands, and offers no way to delete an entry', function () {
    ($this->as)($this->officer)->post('/crm/settings/lists/shop_type', ['name' => 'Tea stall'])->assertForbidden();

    $entry = ($this->lookup)(CrmLookupList::ShopType, 'Food / restaurant');
    ($this->as)($this->owner)->delete("/crm/settings/lists/shop_type/{$entry->id}")->assertStatus(405);
});

it('sends the lead screen every list, retired entries flagged', function () {
    ($this->lookup)(CrmLookupList::ShopType, 'Food / restaurant')->update(['is_active' => false]);

    $lookups = ($this->as)($this->officer)->get('/crm/leads')->viewData('page')['props']['lookups'];

    expect(array_keys($lookups))->toBe(['shop_type', 'supplier_type', 'competitor_brand'])
        ->and(collect($lookups['shop_type'])->firstWhere('name', 'Food / restaurant')['is_active'])->toBeFalse();
});
