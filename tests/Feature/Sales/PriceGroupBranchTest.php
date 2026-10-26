<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\PriceGroupBranch;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer groups with branches (Joy, 26 Oct 2026): "all the customer goes
 * under the Group name Sangram NGO, then all customer under it, with selectable
 * branch name or can be null. same will for Tycoon."
 *
 * ⚠ The rule these tests pin: a customer's branch is always a branch of the
 * customer's own group, whichever door the customer came in by.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Branch Co', 'slug' => 'branch-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    (new PriceGroupSeeder)->run();
    (new WalkInCustomerSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);

    $this->sangram = PriceGroup::create(['code' => 'SANGRAM_NGO', 'name' => 'Sangram NGO', 'falls_back_to_mrp' => false]);
    $this->tycoon = PriceGroup::create(['code' => 'TYCOON', 'name' => 'Tycoon', 'falls_back_to_mrp' => false]);

    $this->file = function (string $body): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'cust').'.csv';
        file_put_contents($path, "code,name,phone,address,price_group,branch\n".$body);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    };
});

it('adds a branch to a group and refuses the same name twice in that group', function () {
    ($this->asOwner)()->post(route('sales.price-groups.branches.store', $this->sangram->id), [
        'name' => 'Kuakata', 'address' => 'Holding No. #306, Alipur, Kalapara', 'phone' => '01709953756',
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->post(route('sales.price-groups.branches.store', $this->sangram->id), ['name' => 'Kuakata'])
        ->assertSessionHasErrors('name');

    // The same name in ANOTHER group is a different branch.
    ($this->asOwner)()->post(route('sales.price-groups.branches.store', $this->tycoon->id), ['name' => 'Kuakata'])
        ->assertSessionHasNoErrors();

    expect(PriceGroupBranch::where('name', 'Kuakata')->count())->toBe(2)
        ->and(PriceGroupBranch::where('price_group_id', $this->sangram->id)->first()->phone)->toBe('01709953756');
});

it('switches a branch off and refuses to edit it through another group', function () {
    $branch = PriceGroupBranch::create(['price_group_id' => $this->sangram->id, 'name' => 'Betagi']);

    ($this->asOwner)()->put(route('sales.price-groups.branches.update', [$this->tycoon->id, $branch->id]), ['name' => 'Betagi', 'is_active' => false])
        ->assertNotFound();

    ($this->asOwner)()->put(route('sales.price-groups.branches.update', [$this->sangram->id, $branch->id]), ['name' => 'Betagi Sadar', 'is_active' => false])
        ->assertSessionHasNoErrors();

    expect($branch->fresh())->name->toBe('Betagi Sadar')->is_active->toBeFalse();
});

it('gives a customer a branch of its own group only', function () {
    $kuakata = PriceGroupBranch::create(['price_group_id' => $this->sangram->id, 'name' => 'Kuakata']);
    $gulshan = PriceGroupBranch::create(['price_group_id' => $this->tycoon->id, 'name' => 'Gulshan']);

    ($this->asOwner)()->post('/sales/customers', [
        'name' => 'Md. Alimuzzman', 'type' => 'retail', 'phone' => '01709953756',
        'price_group_id' => $this->sangram->id, 'price_group_branch_id' => $gulshan->id,
    ])->assertSessionHasErrors('price_group_branch_id');

    ($this->asOwner)()->post('/sales/customers', [
        'name' => 'Md. Alimuzzman', 'type' => 'retail', 'phone' => '01709953756',
        'price_group_id' => $this->sangram->id, 'price_group_branch_id' => $kuakata->id,
    ])->assertSessionHasNoErrors();

    $customer = Customer::where('name', 'Md. Alimuzzman')->firstOrFail();

    expect($customer->price_group_branch_id)->toBe($kuakata->id)
        ->and($customer->priceGroupBranch->name)->toBe('Kuakata');

    // No group, no branch.
    ($this->asOwner)()->put("/sales/customers/{$customer->id}", [
        'name' => 'Md. Alimuzzman', 'type' => 'retail', 'phone' => '01709953756',
        'price_group_id' => null, 'price_group_branch_id' => null,
    ])->assertSessionHasNoErrors();

    expect($customer->fresh()->price_group_branch_id)->toBeNull();
});

it('imports customers into a group branch, adding a new branch once', function () {
    PriceGroupBranch::create(['price_group_id' => $this->sangram->id, 'name' => 'Kuakata']);

    ($this->asOwner)()->post(route('sales.customers.import.review'), ['file' => ($this->file)(
        "SNG-KUAKATA,Md. Alimuzzman,01709953756,Alipur,SANGRAM_NGO,kuakata\n".
        "SNG-BETAGI,Md. Ibrahim Sabuj,01709953737,College Road,SANGRAM_NGO,Betagi\n".
        "SNG-BETAGI-2,Second Betagi,01709953799,College Road,SANGRAM_NGO,Betagi\n"
    )])->assertSessionHas('customerImportPlan', fn ($plan) => $plan['errors'] === []
        && count($plan['creates']) === 3
        && count(array_filter($plan['warnings'], fn ($w) => str_contains($w, 'New branch "Betagi"'))) === 1);

    expect(PriceGroupBranch::count())->toBe(1);

    ($this->asOwner)()->post(route('sales.customers.import.apply'))->assertSessionHasNoErrors();

    TenantScope::enter($this->business->id);
    $betagi = PriceGroupBranch::where('name', 'Betagi')->firstOrFail();

    expect(PriceGroupBranch::count())->toBe(2)
        ->and(Customer::where('code', 'SNG-KUAKATA')->first()->priceGroupBranch->name)->toBe('Kuakata')
        ->and(Customer::where('code', 'SNG-BETAGI')->first()->price_group_branch_id)->toBe($betagi->id)
        ->and(Customer::where('code', 'SNG-BETAGI-2')->first()->price_group_branch_id)->toBe($betagi->id);
});

it('refuses a branch for a customer with no group', function () {
    ($this->asOwner)()->post(route('sales.customers.import.review'), ['file' => ($this->file)(
        "CUS-0001,Somebody,01711000000,Dhaka,,Kuakata\n"
    )])->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
        && str_contains($plan['errors'][0], 'needs a price group'));
});

it('lists each group with its branches on the price groups page', function () {
    PriceGroupBranch::create(['price_group_id' => $this->sangram->id, 'name' => 'Kuakata']);

    ($this->asOwner)()->get(route('sales.price-groups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('groups', fn ($groups) => collect($groups)->firstWhere('code', 'SANGRAM_NGO')['branches'][0]['name'] === 'Kuakata'));
});
