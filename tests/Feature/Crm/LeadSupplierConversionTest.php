<?php

use App\Models\User;
use App\Modules\Crm\Actions\ConvertLeadToSupplier;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2e, part 3: a supplier lead becomes a supplier.
 *
 * ⭐⭐ JOY'S ANSWERS ON TRIAL HERE. Plan D12: converting a supplier lead needs
 * crm.leads.convert AND purchasing.suppliers.manage, so an ASM who converts
 * shops cannot create suppliers. Answer 13: only a code and a name are required.
 * Plan 2e: a supplier lead never becomes a customer, nor the reverse.
 *
 * ⚠ The supplier link keeps every rule the customer link has: one writer, never
 * twice, not while lost, the lead and its history untouched.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Supply Co', 'slug' => 'supply-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $role): User {
        $user = User::factory()->create(['name' => $role]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->person)('Owner');
    $this->salesLead = ($this->person)('Sales Lead');

    $this->lead = fn (string $type = 'supplier', array $extra = []) => Lead::create(array_merge([
        'business_id' => $this->business->id, 'lead_type' => $type, 'name' => 'Sirajganj Mustard Traders',
        'contact_name' => 'Hafiz Uddin', 'phone' => '01713'.random_int(100000, 999999), 'status' => LeadStatus::Qualified,
        'market' => 'Shahzadpur Haat', 'thana' => 'Shahjadpur', 'district' => 'Sirajganj',
        'assigned_to_user_id' => $this->salesLead->id,
    ], $extra));

    $this->convert = fn (User $user, Lead $lead, array $data) => $this->actingAs($user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->post("/crm/leads/{$lead->id}/convert", $data);
});

afterEach(fn () => Carbon::setTestNow());

it('makes a supplier lead a supplier with its proprietor, place, tax numbers and notes', function () {
    $lead = ($this->lead)();

    ($this->convert)($this->owner, $lead, [
        'code' => 'SMT', 'name' => 'Sirajganj Mustard Traders', 'contact_name' => 'Hafiz Uddin',
        'address' => $lead->fullAddress(), 'tin' => '123456789012', 'notes' => 'Producer / primary source. Supplies mustard seed',
    ])->assertSessionHasNoErrors();

    $supplier = Supplier::where('code', 'SMT')->sole();
    $lead->refresh();

    expect($supplier->only(['name', 'contact_name', 'address', 'tin', 'notes', 'is_active']))->toBe([
        'name' => 'Sirajganj Mustard Traders', 'contact_name' => 'Hafiz Uddin',
        'address' => 'Shahzadpur Haat, Shahjadpur, Sirajganj', 'tin' => '123456789012',
        'notes' => 'Producer / primary source. Supplies mustard seed', 'is_active' => true,
    ])->and($lead->converted_supplier_id)->toBe($supplier->id)
        ->and($lead->converted_customer_id)->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->converted_at->toDateTimeString())->toBe('2026-09-15 10:00:00')
        ->and(Customer::count())->toBe(0);
});

it('carries the proprietor and the place across when a caller sends neither', function () {
    ($this->convert)($this->owner, ($this->lead)(), ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders'])->assertSessionHasNoErrors();

    expect(Supplier::where('code', 'SMT')->sole()->only(['contact_name', 'address']))->toBe([
        'contact_name' => 'Hafiz Uddin', 'address' => 'Shahzadpur Haat, Shahjadpur, Sirajganj',
    ]);
});

it('needs only a code and a name, because suppliers are often informal', function () {
    $lead = ($this->lead)('supplier', ['contact_name' => null, 'market' => null, 'thana' => null, 'district' => null]);

    ($this->convert)($this->owner, $lead, ['code' => 'HAAT-1', 'name' => 'Haat Seller'])->assertSessionHasNoErrors();

    expect(Supplier::where('code', 'HAAT-1')->sole()->name)->toBe('Haat Seller');

    ($this->convert)($this->owner, ($this->lead)(), ['name' => 'No Code'])
        ->assertSessionHasErrors(['code' => 'A new supplier needs a permanent code.']);
});

it('links to a supplier we already buy from instead of making a second one', function () {
    $existing = Supplier::create(['business_id' => $this->business->id, 'code' => 'OLD', 'name' => 'Old Trader']);
    $lead = ($this->lead)();

    ($this->convert)($this->owner, $lead, ['supplier_id' => $existing->id])->assertSessionHasNoErrors();

    expect(Supplier::count())->toBe(1)
        ->and($lead->fresh()->converted_supplier_id)->toBe($existing->id);
});

it('refuses a code that already exists, and an inactive supplier to link', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'SMT', 'name' => 'Existing SMT']);
    $retired = Supplier::create(['business_id' => $this->business->id, 'code' => 'GONE', 'name' => 'Retired', 'is_active' => false]);

    ($this->convert)($this->owner, ($this->lead)(), ['code' => 'SMT', 'name' => 'Second SMT'])
        ->assertSessionHasErrors(['code' => 'A supplier with code SMT already exists. Link to them instead of creating a second record.']);
    ($this->convert)($this->owner, ($this->lead)(), ['supplier_id' => $retired->id])
        ->assertSessionHasErrors(['supplier_id' => 'That supplier is no longer active.']);

    expect(Supplier::count())->toBe(2);
});

it('keeps supplier conversion from an ASM who may convert shops but not manage suppliers', function () {
    $lead = ($this->lead)();

    ($this->convert)($this->salesLead, $lead, ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders'])->assertForbidden();

    expect(Supplier::count())->toBe(0)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

it('turns only a supplier lead into a supplier, and never twice or while lost', function () {
    $shop = ($this->lead)('retailer', ['name' => 'Mirpur Store']);

    expect(fn () => app(ConvertLeadToSupplier::class)->handle($shop, $this->owner, newSupplier: ['code' => 'SHOP', 'name' => 'Mirpur Store']))
        ->toThrow(ValidationException::class);

    $lead = ($this->lead)();
    ($this->convert)($this->owner, $lead, ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders'])->assertSessionHasNoErrors();
    ($this->convert)($this->owner, $lead->fresh(), ['code' => 'SMT-2', 'name' => 'Again'])
        ->assertSessionHasErrors(['lead' => 'That lead has already been converted.']);

    $lost = ($this->lead)('supplier', ['status' => LeadStatus::Lost]);
    ($this->convert)($this->owner, $lost, ['code' => 'LOST', 'name' => 'Lost Trader'])
        ->assertSessionHasErrors(['lead' => 'That lead is marked lost. Reopen it before converting.']);

    expect(Supplier::pluck('code')->all())->toBe(['SMT']);
});

it('keeps the scans that arrive with the conversion on the supplier', function () {
    ($this->convert)($this->owner, ($this->lead)(), [
        'code' => 'SMT', 'name' => 'Sirajganj Mustard Traders',
        'documents' => ['nid' => [UploadedFile::fake()->image('hafiz-nid.jpg')]],
    ])->assertSessionHasNoErrors();

    expect(Supplier::where('code', 'SMT')->sole()->attachments()->where('collection', 'nid')->pluck('original_name')->all())->toBe(['hafiz-nid.jpg']);
});

it('cannot be claimed by editing the lead, and a converted supplier lead is no longer editable', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'SMT', 'name' => 'Sirajganj Mustard Traders']);
    $lead = ($this->lead)();

    expect((new Lead)->getFillable())->not->toContain('converted_supplier_id');

    $lead->fill(['converted_supplier_id' => $supplier->id])->save();
    expect($lead->fresh()->converted_supplier_id)->toBeNull();

    ($this->convert)($this->owner, $lead, ['supplier_id' => $supplier->id])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->put("/crm/leads/{$lead->id}", ['name' => 'Renamed', 'phone' => $lead->phone, 'status' => 'qualified', 'lead_type' => 'supplier', 'district' => 'Sirajganj', 'thana' => 'Shahjadpur'])
        ->assertSessionHasErrors(['status' => 'This lead has been converted and can no longer be edited.']);
});

it('shows the supplier list only to someone who may convert suppliers, and names the supplier a lead became', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'OLD', 'name' => 'Old Trader']);
    $lead = ($this->lead)();
    ($this->convert)($this->owner, $lead, ['code' => 'SMT', 'name' => 'Sirajganj Mustard Traders'])->assertSessionHasNoErrors();

    $props = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/crm/leads?show=all')->assertOk()->viewData('page')['props'];

    $asOwner = $props($this->owner);
    $asLead = $props($this->salesLead);

    expect($asOwner['can_convert_supplier'])->toBeTrue()
        ->and(collect($asOwner['suppliers'])->pluck('code')->sort()->values()->all())->toBe(['OLD', 'SMT'])
        ->and(collect($asOwner['leads']['data'])->firstWhere('id', $lead->id)['converted_supplier'])->toBe('Sirajganj Mustard Traders')
        ->and($asLead['can_convert_supplier'])->toBeFalse()
        ->and($asLead['suppliers'])->toBe([]);
});
