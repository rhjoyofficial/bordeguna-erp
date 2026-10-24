<?php

use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseBin;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierType;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesWorld;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): every master list row. Never used
 * = Delete to the Recycle bin with a reason (the Owner restores); used = Delete
 * greyed with WHAT uses it, and Switch off with a reason; Reactivate after.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;
    $this->biz = $this->w->business->id;

    foreach (['inventory.catalog.manage', 'inventory.reports.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::where('name', 'Owner')->where('business_id', $this->biz)->firstOrFail()->givePermissionTo(['inventory.catalog.manage', 'inventory.reports.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->jar = UnitOfMeasure::query()->where('abbreviation', 'jar')->firstOrFail();
    $this->brand = Brand::query()->create(['business_id' => $this->biz, 'code' => 'NEW', 'name' => 'Brand New', 'slug' => 'brand-new']);
    $this->spare = Product::query()->create([
        'business_id' => $this->biz, 'unit_of_measure_id' => $this->jar->id, 'sku' => 'SPARE-1',
        'name' => 'Typed by mistake', 'type' => ProductType::RawMaterial,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('deletes a never-used product, brand, category, unit and bin to the Recycle bin with a reason; the Owner restores', function () {
    $category = Category::query()->create(['business_id' => $this->biz, 'name' => 'Spare shelf', 'slug' => 'spare-shelf']);
    $unit = UnitOfMeasure::query()->create(['business_id' => $this->biz, 'name' => 'Drum', 'abbreviation' => 'drum']);
    $bin = WarehouseBin::query()->create(['business_id' => $this->biz, 'warehouse_id' => $this->s->warehouse->id, 'code' => 'A-01', 'name' => 'Rack A']);

    // A reason is required, five characters at least.
    $this->actingAs($this->w->owner)->post(route('masters.delete', ['product', $this->spare->id]), ['reason_text' => 'no'])
        ->assertSessionHasErrors('reason_text');

    foreach ([['product', $this->spare], ['brand', $this->brand], ['category', $category], ['unit', $unit], ['bin', $bin]] as [$type, $record]) {
        $this->actingAs($this->w->owner)->post(route('masters.delete', [$type, $record->id]), ['reason_text' => 'Typed by mistake'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $trashed = $record::onlyTrashed()->findOrFail($record->id);
        expect($trashed->delete_reason)->toBe('Typed by mistake')
            ->and((int) $trashed->deleted_by)->toBe($this->w->owner->id);
    }

    // The bin lists them for this business's Owner.
    $types = app(RecycleBin::class)->entries($this->w->owner)->pluck('type')->all();
    expect($types)->toContain('product', 'brand', 'category', 'unit', 'bin');

    app(RecycleBin::class)->restore('product', $this->spare->id, $this->w->owner, new ActionReason(null, 'Needed after all'));
    expect(Product::query()->whereKey($this->spare->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'deleted')->count())->toBe(5);
});

it('refuses to delete a used product, unit and warehouse, saying what uses each', function () {
    $honey = $this->s->honey;

    $this->actingAs($this->w->owner)->post(route('masters.delete', ['product', $honey->id]), ['reason_text' => 'Typed by mistake'])
        ->assertSessionHasErrors('reason_text');
    expect(session('errors')->first('reason_text'))->toContain('Product Sundarban Honey 500g (HONEY-500) is used (')
        ->toContain('stock')->toContain('Switch it off instead.');

    // The unit is used by a product through its foreign key.
    expect($this->jar->whyNotRemovable())->toContain('Unit Jar is used (')->toContain('products');

    // The default warehouse is kept by its own rule.
    $this->s->warehouse->update(['is_default' => true]);
    expect($this->s->warehouse->fresh()->whyNotRemovable())->toContain('default warehouse stays');

    expect(Product::query()->whereKey($honey->id)->exists())->toBeTrue();
});

it('a category with a sub-category or a product is used; the self reference counts, the row itself does not', function () {
    $parent = Category::query()->create(['business_id' => $this->biz, 'name' => 'Oils', 'slug' => 'oils']);
    expect($parent->whyNotRemovable())->toBeNull();

    $this->spare->update(['category_id' => $parent->id]);
    expect($parent->fresh()->whyNotRemovable())->toContain('Category Oils is used (1 products)');
});

it('switches a used product off with a reason, logged, and reactivates it', function () {
    $honey = $this->s->honey;

    $this->actingAs($this->w->owner)->post(route('masters.switch-off', ['product', $honey->id]), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->owner)->post(route('masters.switch-off', ['product', $honey->id]), ['reason_text' => 'No longer sold'])
        ->assertSessionHasNoErrors();
    expect($honey->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'deactivated')->value('properties')['reason'] ?? null)->toBe('No longer sold');

    // A double click finds it already off.
    $this->actingAs($this->w->owner)->post(route('masters.switch-off', ['product', $honey->id]), ['reason_text' => 'No longer sold'])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->w->owner)->post(route('masters.reactivate', ['product', $honey->id]))->assertSessionHasNoErrors();
    expect($honey->fresh()->is_active)->toBeTrue();

    // A unit has no on/off switch.
    $this->actingAs($this->w->owner)->post(route('masters.switch-off', ['unit', $this->jar->id]), ['reason_text' => 'No longer sold'])->assertNotFound();
});

it('the default warehouse cannot be switched off', function () {
    $this->s->warehouse->update(['is_default' => true]);

    $this->actingAs($this->w->owner)->post(route('masters.switch-off', ['warehouse', $this->s->warehouse->id]), ['reason_text' => 'Closing it down'])
        ->assertSessionHasErrors('reason_text');
    expect($this->s->warehouse->fresh()->is_active)->toBeTrue();
});

it('needs the list permission, and another business row is not found', function () {
    $this->actingAs($this->s->cashier)->post(route('masters.delete', ['product', $this->spare->id]), ['reason_text' => 'Typed by mistake'])->assertForbidden();

    $foreign = Brand::withoutGlobalScopes()->create(['business_id' => $this->w->other->id, 'code' => 'OTH', 'name' => 'Other', 'slug' => 'other']);
    $this->actingAs($this->w->owner)->post(route('masters.delete', ['brand', $foreign->id]), ['reason_text' => 'Typed by mistake'])->assertNotFound();
    $this->actingAs($this->w->owner)->post(route('masters.delete', ['journal', 1]), ['reason_text' => 'Typed by mistake'])->assertNotFound();
});

it('the old list Delete routes go to the bin too, refusing a used unit in words', function () {
    $this->actingAs($this->w->owner)->from('/inventory/units')->delete(route('inventory.units.destroy', $this->jar))
        ->assertRedirect('/inventory/units')->assertSessionHas('error');

    $drum = UnitOfMeasure::query()->create(['business_id' => $this->biz, 'name' => 'Drum', 'abbreviation' => 'drum']);
    $this->actingAs($this->w->owner)->delete(route('inventory.units.destroy', $drum))->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->owner)->delete(route('inventory.units.destroy', $drum), ['reason_text' => 'Typed by mistake'])->assertSessionHas('success');
    expect(UnitOfMeasure::onlyTrashed()->whereKey($drum->id)->exists())->toBeTrue();

    // A binned unit still owns its abbreviation: a new "drum" is refused in words, not a 500.
    $this->actingAs($this->w->owner)->post(route('inventory.units.store'), ['name' => 'Drum', 'abbreviation' => 'drum'])->assertSessionHasErrors('abbreviation');
});

it('list pages carry each row\'s actions in one pass', function () {
    $this->actingAs($this->w->owner)->get(route('inventory.brands.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("recordActions.{$this->brand->id}.delete_blocked", null)
            ->where("recordActions.{$this->brand->id}.is_active", true)
            ->has('deleteReasons'));

    $rows = MasterRecords::rows('product', Product::query()->whereKey([$this->spare->id, $this->s->honey->id])->get(), $this->w->owner);
    expect($rows[$this->spare->id]['delete_blocked'])->toBeNull()
        ->and($rows[$this->s->honey->id]['delete_blocked'])->toContain('is used (');

    // Someone who may not change the list sees the reason, not a scan.
    $rows = MasterRecords::rows('product', Product::query()->whereKey($this->spare->id)->get(), $this->s->cashier);
    expect($rows[$this->spare->id]['may'])->toBeFalse()->and($rows[$this->spare->id]['delete_blocked'])->toBe('You may not change this list.');
});

it('the product list shows every page row with its actions', function () {
    $this->actingAs($this->w->owner)->get('/inventory/raw-materials')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where("recordActions.{$this->spare->id}.delete_blocked", null));
});

// --- Price groups, bank and cash accounts, supplier types ---------------------

beforeEach(function () {
    $names = ['sales.prices.manage', 'accounting.money_account.manage', 'purchasing.suppliers.manage'];
    foreach ($names as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::where('name', 'Owner')->where('business_id', $this->biz)->firstOrFail()->givePermissionTo($names);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('a never-used bank account goes to the bin and takes its own ledger head off with it; a used one stays', function () {
    $fresh = MoneyAccount::query()->create(['business_id' => $this->biz, 'name' => 'Typo Bank', 'type' => 'bank', 'is_active' => true]);
    MoneyLedgers::ensureOwnLedger($fresh);
    $head = $fresh->fresh()->account_id;
    expect($head)->not->toBeNull();

    $this->actingAs($this->w->owner)->post(route('masters.delete', ['money_account', $fresh->id]), ['reason_text' => 'Typed by mistake'])
        ->assertSessionHasNoErrors();
    expect(MoneyAccount::onlyTrashed()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and((bool) DB::table('accounts')->where('id', $head)->value('is_active'))->toBeFalse();

    app(RecycleBin::class)->restore('money_account', $fresh->id, $this->w->owner, new ActionReason(null, 'Needed after all'));
    expect((bool) DB::table('accounts')->where('id', $head)->value('is_active'))->toBeTrue();

    // The world's bank has postings.
    $this->w->record('5000', '2026-10-15');
    expect($this->w->bank->fresh()->whyNotRemovable())->toContain('Money account BRAC Bank');
});

it('the retail price group is kept and never switched off; another unused group goes to the bin', function () {
    $retail = PriceGroup::query()->firstOrCreate(['business_id' => $this->biz, 'code' => PriceGroup::DEFAULT_CODE], ['name' => 'Retail']);
    expect($retail->whyNotRemovable())->toContain('part of the system')
        ->and($retail->whyNotDeactivatable())->toContain('cannot be switched off');

    $dealer = PriceGroup::query()->create(['business_id' => $this->biz, 'code' => 'TYPO', 'name' => 'Typo group']);
    $this->actingAs($this->w->owner)->post(route('masters.delete', ['price_group', $dealer->id]), ['reason_text' => 'Typed by mistake'])
        ->assertSessionHasNoErrors();
    expect(PriceGroup::onlyTrashed()->whereKey($dealer->id)->exists())->toBeTrue();

    $this->actingAs($this->w->owner)->get(route('sales.price-groups.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where("recordActions.{$retail->id}.switch_blocked", fn ($why) => str_contains((string) $why, 'cannot be switched off')));
});

it('a supplier type used by key is kept with what uses it; an unused one goes to the bin', function () {
    $type = SupplierType::query()->create(['business_id' => $this->biz, 'key' => 'printing', 'name' => 'Printing']);
    expect($type->whyNotRemovable())->toBeNull();

    Supplier::query()->create(['business_id' => $this->biz, 'code' => 'PRN', 'name' => 'Press Ltd', 'supplier_type' => 'printing', 'is_active' => true]);
    expect($type->fresh()->whyNotRemovable())->toContain('Supplier type Printing is used (1 suppliers)');

    $spare = SupplierType::query()->create(['business_id' => $this->biz, 'key' => 'spare', 'name' => 'Spare']);
    $this->actingAs($this->w->owner)->post(route('masters.delete', ['supplier_type', $spare->id]), ['reason_text' => 'Typed by mistake'])
        ->assertSessionHasNoErrors();
    expect(SupplierType::onlyTrashed()->whereKey($spare->id)->exists())->toBeTrue();
});

it('organization units: a binned section no longer keeps its department; restoring it waits for the department', function () {
    Permission::findOrCreate('foundation.organization.manage', 'web');
    Role::where('name', 'Owner')->where('business_id', $this->biz)->firstOrFail()->givePermissionTo('foundation.organization.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $office = Office::query()->create(['business_id' => $this->biz, 'name' => 'Savar office', 'code' => 'SVO']);
    $department = Department::query()->create(['business_id' => $this->biz, 'office_id' => $office->id, 'name' => 'Packing']);
    $section = Section::query()->create(['business_id' => $this->biz, 'department_id' => $department->id, 'name' => 'Night line']);

    expect($department->whyNotRemovable())->toContain('Department Packing is used (1 sections)');

    $this->actingAs($this->w->owner)->post(route('masters.delete', ['section', $section->id]), ['reason_text' => 'Typed by mistake'])->assertSessionHasNoErrors();
    expect($department->fresh()->whyNotRemovable())->toBeNull();

    $this->actingAs($this->w->owner)->post(route('masters.delete', ['department', $department->id]), ['reason_text' => 'Typed by mistake'])->assertSessionHasNoErrors();

    expect(fn () => app(RecycleBin::class)->restore('section', $section->id, $this->w->owner, new ActionReason(null, 'Needed after all')))
        ->toThrow(ValidationException::class, 'Restore departments #'.$department->id.' first');

    app(RecycleBin::class)->restore('department', $department->id, $this->w->owner, new ActionReason(null, 'Needed after all'));
    app(RecycleBin::class)->restore('section', $section->id, $this->w->owner, new ActionReason(null, 'Needed after all'));
    expect(Section::query()->whereKey($section->id)->exists())->toBeTrue();

    $this->actingAs($this->w->owner)->get(route('organization.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where("recordActions.office.{$office->id}.delete_blocked", fn ($why) => str_contains((string) $why, '1 departments')));
});
