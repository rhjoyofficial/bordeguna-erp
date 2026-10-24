<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierType;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\SupplierTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supplier types as a list each business keeps (Joy, 17 Oct 2026: he needs
 * more than the seven fixed ones). Purchases > Suppliers > Supplier types.
 *
 * ⚠⚠ Suppliers and TDS/VDS rates store the type's KEY. The seven keys every
 * business already used must stay exactly as they were, a used key is frozen,
 * and a used type is switched off rather than deleted.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Types Co', 'slug' => 'types-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Types Co', 'slug' => 'rival-types-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $this->type = fn (string $key) => SupplierType::query()->where('key', $key)->sole();
});

it('gives every business the seven types it already used, with the same keys', function () {
    foreach ([$this->business, $this->other] as $business) {
        $keys = DB::table('supplier_types')->where('business_id', $business->id)->orderBy('sort')->pluck('key')->all();

        expect($keys)->toBe(['goods', 'service', 'contractor', 'transport', 'landlord', 'professional', 'other']);
    }

    expect(SupplierTypes::options()[0])->toBe(['key' => 'goods', 'name' => 'Goods supplier', 'active' => true]);
});

it('seeds existing businesses in the migration and keeps every stored key working', function () {
    // A key the old validation should never have let in, already on a supplier.
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ODD', 'name' => 'Odd Ltd', 'supplier_type' => 'cnf_agent']);
    WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'goods', 'name' => 'Goods', 'rate' => '5', 'effective_from' => '2026-07-01']);

    // Run the migration as it runs on the live database: table gone, data there.
    Schema::drop('supplier_types');
    (require base_path('app/Modules/Purchasing/database/migrations/2026_10_17_400000_supplier_types_are_a_list_each_business_keeps.php'))->up();
    // B24 (24 Oct 2026): the later Recycle bin columns, as their own migration adds them.
    Schema::table('supplier_types', function (Blueprint $table) {
        $table->softDeletes();
        $table->foreignId('deleted_by')->nullable();
        $table->text('delete_reason')->nullable();
    });

    expect(SupplierType::query()->ordered()->pluck('key')->all())
        ->toBe(['goods', 'service', 'contractor', 'transport', 'landlord', 'professional', 'other', 'cnf_agent'])
        ->and(DB::table('supplier_types')->where('business_id', $this->other->id)->count())->toBe(7)
        // Nothing on the suppliers or the rates changed.
        ->and(Supplier::where('code', 'ODD')->value('supplier_type'))->toBe('cnf_agent')
        ->and(WithholdingRate::sole()->supplier_type)->toBe('goods');
});

it('lists the types with what uses each, and adds one with a key made from its name', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'Acme', 'supplier_type' => 'goods']);

    ($this->asRole)('Manager')->get('/purchasing/supplier-types')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/supplier-types/index')
            ->where('canManageTypes', true)
            ->has('types', 7)
            ->where('types.0.key', 'goods')
            ->where('types.0.suppliers', 1)
            ->where('types.1.suppliers', 0));

    ($this->asRole)('Manager')->post('/purchasing/supplier-types', ['name' => 'C&F agent / Clearing'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $added = SupplierType::query()->where('name', 'C&F agent / Clearing')->sole();

    expect($added->key)->toBe('c_f_agent_clearing')
        ->and($added->is_active)->toBeTrue()
        ->and($added->sort)->toBe(7);

    // Same name twice is refused; a key may also be typed by hand.
    ($this->asRole)('Manager')->post('/purchasing/supplier-types', ['name' => 'C&F agent / Clearing'])->assertSessionHasErrors('name');
    ($this->asRole)('Manager')->post('/purchasing/supplier-types', ['name' => 'Packaging', 'key' => 'Packaging'])->assertSessionHasNoErrors();
    ($this->asRole)('Manager')->post('/purchasing/supplier-types', ['name' => 'Bad key', 'key' => 'bad key!'])->assertSessionHasErrors('key');

    expect(($this->type)('packaging')->name)->toBe('Packaging');
});

it('renames a type freely, but freezes its key once something uses it', function () {
    $raw = SupplierType::create(['business_id' => $this->business->id, 'key' => 'raw', 'name' => 'Raw']);

    // Unused: key and name both change.
    ($this->asRole)('Manager')->put("/purchasing/supplier-types/{$raw->id}", ['name' => 'Raw material', 'key' => 'raw_material'])
        ->assertSessionHasNoErrors();
    expect($raw->fresh()->only(['key', 'name']))->toBe(['key' => 'raw_material', 'name' => 'Raw material']);

    // Used by a supplier: the name changes, the key does not.
    Supplier::create(['business_id' => $this->business->id, 'code' => 'PREMI', 'name' => 'Premi Farm', 'supplier_type' => 'raw_material']);

    ($this->asRole)('Manager')->put("/purchasing/supplier-types/{$raw->id}", ['name' => 'Raw materials', 'key' => 'raw_materials'])
        ->assertSessionHasErrors('key');
    ($this->asRole)('Manager')->put("/purchasing/supplier-types/{$raw->id}", ['name' => 'Raw materials', 'key' => 'raw_material'])
        ->assertSessionHasNoErrors();

    expect($raw->fresh()->only(['key', 'name']))->toBe(['key' => 'raw_material', 'name' => 'Raw materials'])
        ->and(Supplier::where('code', 'PREMI')->value('supplier_type'))->toBe('raw_material');
});

it('switches a type off and on, and deletes one only while nothing uses it', function () {
    $unused = SupplierType::create(['business_id' => $this->business->id, 'key' => 'printing', 'name' => 'Printing']);

    ($this->asRole)('Manager')->post("/purchasing/supplier-types/{$unused->id}/deactivate")->assertSessionHasNoErrors();
    expect($unused->fresh()->is_active)->toBeFalse();

    ($this->asRole)('Manager')->post("/purchasing/supplier-types/{$unused->id}/reactivate")->assertSessionHasNoErrors();
    expect($unused->fresh()->is_active)->toBeTrue();

    ($this->asRole)('Manager')->delete("/purchasing/supplier-types/{$unused->id}", ['reason_text' => 'Never used it'])->assertSessionHasNoErrors();
    expect(SupplierType::query()->where('key', 'printing')->exists())->toBeFalse();
});

it('refuses to delete a type a supplier or a tax rate uses, and says why', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'Acme', 'supplier_type' => 'goods', 'is_active' => false]);
    WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'landlord', 'name' => 'Rent', 'rate' => '5', 'effective_from' => '2026-07-01']);

    // ⚠ A switched-off supplier still counts: it still says what it was.
    ($this->asRole)('Manager')->delete('/purchasing/supplier-types/'.($this->type)('goods')->id)
        ->assertSessionHasErrors(['type' => 'Goods supplier is used by 1 supplier, so it cannot be deleted. Switch it off instead.']);
    ($this->asRole)('Manager')->delete('/purchasing/supplier-types/'.($this->type)('landlord')->id)
        ->assertSessionHasErrors(['type' => 'Landlord (rent) is used by 1 tax rate, so it cannot be deleted. Switch it off instead.']);

    expect(SupplierType::query()->whereIn('key', ['goods', 'landlord'])->count())->toBe(2);
});

it('keeps another business\'s types out of sight and out of reach', function () {
    TenantScope::enter($this->other->id);
    $theirs = SupplierType::create(['business_id' => $this->other->id, 'key' => 'their_own', 'name' => 'Their own']);
    TenantScope::enter($this->business->id);

    ($this->asRole)('Manager')->get('/purchasing/supplier-types')
        ->assertInertia(fn (Assert $page) => $page->has('types', 7));

    ($this->asRole)('Manager')->put("/purchasing/supplier-types/{$theirs->id}", ['name' => 'Mine now'])->assertNotFound();
    ($this->asRole)('Manager')->post("/purchasing/supplier-types/{$theirs->id}/deactivate")->assertNotFound();
    ($this->asRole)('Manager')->delete("/purchasing/supplier-types/{$theirs->id}")->assertNotFound();

    // Their key is not a type here: not on a supplier, not on a rate.
    ($this->asRole)('Manager')->post('/purchasing/suppliers', ['code' => 'X1', 'name' => 'X One', 'supplier_type' => 'their_own'])
        ->assertSessionHasErrors('supplier_type');
    ($this->asRole)('Manager')->post(route('purchasing.withholding-rates.store'), [
        'kind' => 'tds', 'supplier_type' => 'their_own', 'name' => 'Theirs', 'rate' => '5', 'effective_from' => '2026-07-01',
    ])->assertSessionHasErrors('supplier_type');

    // Same key, two businesses: each keeps its own.
    ($this->asRole)('Manager')->post('/purchasing/supplier-types', ['name' => 'Their own'])->assertSessionHasNoErrors();
    expect(($this->type)('their_own')->business_id)->toBe($this->business->id)
        ->and($theirs->fresh()->name)->toBe('Their own');
});

it('lets the supplier form and the tax rate form use a new type', function () {
    SupplierType::create(['business_id' => $this->business->id, 'key' => 'cnf_agent', 'name' => 'C&F agent', 'sort' => 99]);

    ($this->asRole)('Manager')->get('/purchasing/suppliers')
        ->assertInertia(fn (Assert $page) => $page->where('supplierTypes.7', ['key' => 'cnf_agent', 'name' => 'C&F agent', 'active' => true]));

    ($this->asRole)('Manager')->post('/purchasing/suppliers', ['code' => 'CNF', 'name' => 'Port Clearing', 'supplier_type' => 'cnf_agent'])
        ->assertSessionHasNoErrors();
    expect(Supplier::where('code', 'CNF')->value('supplier_type'))->toBe('cnf_agent');

    ($this->asRole)('Manager')->post(route('purchasing.withholding-rates.store'), [
        'kind' => 'tds', 'supplier_type' => 'cnf_agent', 'name' => 'C&F commission', 'rate' => '10', 'effective_from' => '2026-07-01',
    ])->assertSessionHasNoErrors();
    expect(WithholdingRate::sole()->supplier_type)->toBe('cnf_agent');

    ($this->asRole)('Manager')->get('/purchasing/withholding-rates')
        ->assertInertia(fn (Assert $page) => $page->where('supplierTypes.7.key', 'cnf_agent'));
});

it('imports a new type by key or by name in any case, and names the allowed types for an unknown one', function () {
    SupplierType::create(['business_id' => $this->business->id, 'key' => 'cnf_agent', 'name' => 'C&F agent', 'sort' => 99]);

    $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($path, "code,name,supplier_type\nCNF1,Port One,CNF_AGENT\nCNF2,Port Two,c&f AGENT\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'types-co', '--apply' => true])->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::query()->orderBy('code')->pluck('supplier_type')->all())->toBe(['cnf_agent', 'cnf_agent']);

    $bad = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($bad, "code,name,supplier_type\nPREMI,Premi Farm,farmer\n");

    $this->artisan('suppliers:import', ['file' => $bad, '--business' => 'types-co', '--apply' => true])
        ->expectsOutputToContain('"farmer" is not a supplier type. Use one of: goods, service, contractor, transport, landlord, professional, other, cnf_agent.')
        ->assertFailed();
});

it('hides a switched-off type from new choices but keeps it on the suppliers that have it', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'OLD', 'name' => 'Old Landlord', 'supplier_type' => 'landlord']);
    $old = Supplier::where('code', 'OLD')->sole();
    $rate = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'landlord', 'name' => 'Rent', 'rate' => '5', 'effective_from' => '2026-07-01']);

    ($this->asRole)('Manager')->post('/purchasing/supplier-types/'.($this->type)('landlord')->id.'/deactivate')->assertSessionHasNoErrors();

    // The picker still knows its name (for the supplier that has it), marked off.
    expect(collect(SupplierTypes::options())->firstWhere('key', 'landlord'))->toBe(['key' => 'landlord', 'name' => 'Landlord (rent)', 'active' => false])
        ->and(SupplierTypes::allowedKeys())->not->toContain('landlord')
        ->and(SupplierTypes::allowedKeys('landlord'))->toContain('landlord');

    // Not for a new supplier or a new rate...
    ($this->asRole)('Manager')->post('/purchasing/suppliers', ['code' => 'NEW', 'name' => 'New Landlord', 'supplier_type' => 'landlord'])
        ->assertSessionHasErrors('supplier_type');
    ($this->asRole)('Manager')->post(route('purchasing.withholding-rates.store'), [
        'kind' => 'vds', 'supplier_type' => 'landlord', 'name' => 'Rent VAT', 'rate' => '15', 'effective_from' => '2026-07-01',
    ])->assertSessionHasErrors('supplier_type');

    // ...but saving the supplier and the rate that already have it still works.
    ($this->asRole)('Manager')->put("/purchasing/suppliers/{$old->id}", ['code' => 'OLD', 'name' => 'Old Landlord Ltd', 'supplier_type' => 'landlord'])
        ->assertSessionHasNoErrors();
    ($this->asRole)('Manager')->put(route('purchasing.withholding-rates.update', $rate->id), [
        'kind' => 'tds', 'supplier_type' => 'landlord', 'name' => 'Rent (old)', 'rate' => '5', 'effective_from' => '2026-07-01',
    ])->assertSessionHasNoErrors();

    expect($old->fresh()->only(['name', 'supplier_type']))->toBe(['name' => 'Old Landlord Ltd', 'supplier_type' => 'landlord']);

    // The file: an exported row re-imported unchanged goes through; a new
    // supplier on the switched-off type does not.
    $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($path, "code,name,supplier_type\nOLD,Old Landlord Ltd,landlord\n");
    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'types-co', '--apply' => true])->assertSuccessful();

    $bad = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($bad, "code,name,supplier_type\nNEWL,New Landlord,Landlord (rent)\n");
    $this->artisan('suppliers:import', ['file' => $bad, '--business' => 'types-co', '--apply' => true])
        ->expectsOutputToContain('is a switched-off supplier type')
        ->assertFailed();
});

it('accepts a new type through the screen import as well', function () {
    SupplierType::create(['business_id' => $this->business->id, 'key' => 'cnf_agent', 'name' => 'C&F agent', 'sort' => 99]);

    $file = UploadedFile::fake()->createWithContent('suppliers.csv', "code,name,supplier_type\nCNF,Port Clearing,C&F agent\n");

    $owner = ($this->asRole)('Owner');
    $owner->post('/purchasing/suppliers/import/review', ['file' => $file])->assertSessionHasNoErrors();
    $owner->post('/purchasing/suppliers/import/apply')->assertSessionHas('success');

    TenantScope::enter($this->business->id);
    expect(Supplier::where('code', 'CNF')->value('supplier_type'))->toBe('cnf_agent');
});

it('needs purchasing.suppliers.manage for every part of the screen', function () {
    $id = ($this->type)('other')->id;
    $staff = fn () => ($this->asRole)('Staff');

    $staff()->get('/purchasing/supplier-types')->assertForbidden();
    $staff()->post('/purchasing/supplier-types', ['name' => 'Sneaky'])->assertForbidden();
    $staff()->put("/purchasing/supplier-types/{$id}", ['name' => 'Sneaky'])->assertForbidden();
    $staff()->post("/purchasing/supplier-types/{$id}/deactivate")->assertForbidden();
    $staff()->post("/purchasing/supplier-types/{$id}/reactivate")->assertForbidden();
    $staff()->delete("/purchasing/supplier-types/{$id}")->assertForbidden();

    expect(SupplierType::query()->count())->toBe(7)
        ->and(($this->type)('other')->name)->toBe('Other');
});
