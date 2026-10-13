<?php

use App\Models\User;
use App\Modules\Crm\Actions\ConvertLead;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2e: the customer a lead becomes follows the lead's type.
 *
 * ⭐⭐ JOY'S ANSWERS ON TRIAL HERE (plan approved 15 Sep 2026). D6: a distributor
 * lead preselects Distributor and the DISTRIBUTOR price group, both changeable.
 * D7 (answer 11): credit limit and terms only for Retail and Corporate, because
 * distributors pay in advance and a dealer is a distributor tier (answer 10).
 * Plan 2e: a supplier lead never becomes a customer; the proprietor and the
 * place carry across.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Type Co', 'slug' => 'type-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Type Co', 'slug' => 'rival-type-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // ⚠ The customer screen's own gates apply to conversion too (audit, 13 Oct
    // 2026): credit needs sales.credit.approve and a price group needs
    // sales.customers.manage, both of which a real Owner holds.
    foreach (['sales.credit.approve', 'sales.customers.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['sales.credit.approve', 'sales.customers.manage']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    foreach (['RETAIL' => 'Retail', 'DEALER' => 'Dealer', 'DISTRIBUTOR' => 'Distributor', 'CORPORATE' => 'Corporate'] as $code => $name) {
        $this->groups[$code] = PriceGroup::create(['business_id' => $this->business->id, 'code' => $code, 'name' => $name]);
    }

    $this->lead = fn (string $type, array $extra = []) => Lead::create(array_merge([
        'business_id' => $this->business->id, 'lead_type' => $type, 'name' => 'Meghna Distribution',
        'contact_name' => 'Abdul Karim', 'phone' => '01712'.random_int(100000, 999999), 'status' => LeadStatus::Qualified,
        'address' => 'Holding 14, Road 2', 'market' => 'Kaptan Bazar', 'thana' => 'Sutrapur', 'district' => 'Dhaka',
    ], $extra));

    $this->convert = fn (Lead $lead, array $data) => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->post("/crm/leads/{$lead->id}/convert", $data);
});

it('never turns a supplier lead into a customer, new or existing', function () {
    $supplier = ($this->lead)('supplier', ['name' => 'Sirajganj Mustard Traders']);
    $existing = Customer::create(['business_id' => $this->business->id, 'code' => 'OLD-1', 'name' => 'Old Customer']);

    // ⚠ Since part 3 the endpoint sends a supplier lead down the SUPPLIER branch
    // (this Owner may not manage suppliers, so it refuses), and ConvertLead
    // refuses one for any caller that reaches it directly.
    ($this->convert)($supplier, ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders', 'customer_id' => $existing->id])->assertForbidden();

    foreach ([['existing' => $existing], ['newCustomer' => ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders']]] as $how) {
        expect(fn () => app(ConvertLead::class)->handle($supplier, $this->owner, ...$how))
            ->toThrow(ValidationException::class, 'A supplier lead becomes a supplier, not a customer.');
    }

    expect(Customer::where('code', 'SMT')->exists())->toBeFalse()
        ->and($supplier->fresh()->converted_customer_id)->toBeNull()
        ->and($supplier->fresh()->status)->toBe(LeadStatus::Qualified);
});

it('makes a distributor a Distributor on the DISTRIBUTOR price, with its proprietor, place and tax numbers', function () {
    $lead = ($this->lead)('distributor');

    ($this->convert)($lead, [
        'code' => 'MEGHNA', 'name' => 'Meghna Distribution', 'type' => 'distributor',
        'price_group_id' => $this->groups['DISTRIBUTOR']->id, 'contact_name' => 'Abdul Karim',
        'address' => $lead->fullAddress(), 'bin' => '000123456-0101', 'tin' => '123456789012',
    ])->assertSessionHasNoErrors();

    $customer = Customer::where('code', 'MEGHNA')->sole();

    expect($customer->type)->toBe(CustomerType::Distributor)
        ->and($customer->price_group_id)->toBe($this->groups['DISTRIBUTOR']->id)
        ->and($customer->contact_name)->toBe('Abdul Karim')
        ->and($customer->address)->toBe('Holding 14, Road 2, Kaptan Bazar, Sutrapur, Dhaka')
        ->and($customer->only(['bin', 'tin']))->toBe(['bin' => '000123456-0101', 'tin' => '123456789012']);
});

it('carries the proprietor and the place across when a caller sends neither', function () {
    $lead = ($this->lead)('retailer', ['address' => null, 'market' => 'Mirpur 10 Bazar', 'thana' => 'Mirpur']);

    ($this->convert)($lead, ['code' => 'M10', 'name' => 'Mirpur 10 Store'])->assertSessionHasNoErrors();

    expect(Customer::where('code', 'M10')->sole()->only(['contact_name', 'address']))->toBe([
        'contact_name' => 'Abdul Karim', 'address' => 'Mirpur 10 Bazar, Dhaka',
    ]);
});

it('gives credit only to a Retail or Corporate customer, whatever the form sends', function (string $type, bool $keeps) {
    $lead = ($this->lead)('retailer');

    ($this->convert)($lead, [
        'code' => 'C-'.strtoupper($type), 'name' => 'Credit Check', 'type' => $type,
        'credit_limit' => '50000', 'payment_terms' => 'Weekly',
    ])->assertSessionHasNoErrors();

    $customer = Customer::where('code', 'C-'.strtoupper($type))->sole();

    expect($customer->credit_limit !== null)->toBe($keeps)
        ->and($customer->payment_terms !== null)->toBe($keeps);
})->with([
    'retail keeps it' => ['retail', true],
    'corporate keeps it' => ['corporate', true],
    'dealer loses it' => ['dealer', false],
    'distributor loses it' => ['distributor', false],
]);

/**
 * ⚠⚠ Audit, 13 Oct 2026: conversion needs only crm.leads.convert, so it set
 * credit and any price group the customer screen would have refused.
 */
it('drops credit and the price group for a converter who may not grant them', function () {
    Role::create(['name' => 'Converter', 'guard_name' => 'web', 'business_id' => $this->business->id])
        ->givePermissionTo(Permission::where('name', 'like', 'crm.%')->pluck('name'));
    $officer = User::factory()->create();
    $officer->businesses()->attach($this->business->id);
    $officer->assignRole('Converter');

    $this->actingAs($officer)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post('/crm/leads/'.($this->lead)('retailer')->id.'/convert', [
            'code' => 'NOCREDIT', 'name' => 'No Credit', 'type' => 'retail',
            'credit_limit' => '50000', 'payment_terms' => 'Weekly',
            'price_group_id' => $this->groups['DEALER']->id,
        ])->assertSessionHasNoErrors();

    expect(Customer::where('code', 'NOCREDIT')->sole()->only(['credit_limit', 'payment_terms', 'price_group_id']))
        ->toBe(['credit_limit' => null, 'payment_terms' => null, 'price_group_id' => null]);
});

it('refuses a price group from another business', function () {
    TenantScope::enter($this->other->id);
    app(BusinessContext::class)->set($this->other->id);
    $foreign = PriceGroup::create(['business_id' => $this->other->id, 'code' => 'VIP', 'name' => 'Rival VIP']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    ($this->convert)(($this->lead)('retailer'), ['code' => 'FOREIGN', 'name' => 'Foreign Group', 'price_group_id' => $foreign->id])
        ->assertSessionHasErrors('price_group_id');

    expect(Customer::where('code', 'FOREIGN')->exists())->toBeFalse();
});

it('gives the conversion form the active price groups and the types that take credit', function () {
    $this->groups['CORPORATE']->update(['is_active' => false]);
    ($this->lead)('distributor');

    $props = $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/crm/leads')->assertOk()->viewData('page')['props'];

    expect(collect($props['price_groups'])->pluck('code')->sort()->values()->all())->toBe(['DEALER', 'DISTRIBUTOR', 'RETAIL'])
        ->and($props['credit_types'])->toBe(['retail', 'shop', 'corporate'])
        ->and($props['leads']['data'][0]['full_address'])->toBe('Holding 14, Road 2, Kaptan Bazar, Sutrapur, Dhaka');
});
