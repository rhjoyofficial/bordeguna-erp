<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Assets\Actions\ChargeDepreciation;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Support\AssetTag;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset management slice A1 (Joy, 16 September 2026): the PHYSICAL register of
 * what a business keeps. Plan: ASSET-MANAGEMENT-PLAN-2026-09-16.md.
 *
 * ⭐ Registering a thing never touches the ledger; its tag is permanent; where it
 * is must be one real place of the business; the Asset Keeper keeps the things
 * and never sees a price.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-16 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    (new AssetCategorySeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AssetAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $role, ?Business $business = null): User {
        $business ??= $this->business;
        $user = User::factory()->create();
        $user->businesses()->attach($business->id);
        setPermissionsTeamId($business->id);
        $user->assignRole($role);
        setPermissionsTeamId($this->business->id);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->keeper = ($this->user)('Asset Keeper');
    $this->staff = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);

    $this->category = fn (string $code) => AssetCategory::query()->where('code', $code)->sole();

    $this->attributes = fn (array $overrides = []) => array_replace([
        'name' => 'Desk chair',
        'office_id' => $this->office->id,
        'warehouse_id' => null,
        'status' => 'in_use',
        'condition' => 'good',
    ], $overrides);

    $this->register = fn (string $code = 'FURN', array $overrides = [], int $copies = 1, ?User $by = null) => app(SaveAssetItem::class)
        ->register(($this->category)($code), ($this->attributes)($overrides), $by ?? $this->owner, $copies);

    $this->form = fn (array $overrides = []) => array_replace([
        'asset_category_id' => ($this->category)('IT')->id,
        'tracking' => 'individual',
        'copies' => 1,
        'name' => 'ThinkPad T470s',
        'brand' => 'Lenovo',
        'model' => 'T470s',
        'serial_number' => 'PF-1A2B3C',
        'place' => 'office:'.$this->office->id,
        'area' => 'Software desk',
        'status' => 'in_use',
        'condition' => 'good',
        'purchase_price' => '45000',
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

it('registers ten identical chairs as ten tags in order, and posts nothing', function () {
    $chairs = ($this->register)('FURN', [], 10);

    expect($chairs)->toHaveCount(10)
        ->and($chairs->pluck('tag')->first())->toBe('BDG-FURN-0001')
        ->and($chairs->pluck('tag')->last())->toBe('BDG-FURN-0010')
        ->and($chairs->every(fn (AssetItem $chair) => $chair->quantity === 1 && $chair->tracking === AssetTracking::Individual))->toBeTrue()
        ->and(JournalLine::query()->count())->toBe(0)
        ->and(FixedAsset::query()->count())->toBe(0);

    // The next one carries on the numbering, and another category starts its own.
    expect(($this->register)('FURN')->first()->tag)->toBe('BDG-FURN-0011')
        ->and(($this->register)('IT', ['name' => 'Asus VivoBook'])->first()->tag)->toBe('BDG-IT-0001');
});

it('keeps a counted group as one record with its quantity, and refuses copies or a serial for many', function () {
    $scissors = ($this->register)('SML', ['name' => 'Steel scissors', 'quantity' => 2, 'tracking' => 'quantity'])->sole();

    expect($scissors->tracking)->toBe(AssetTracking::Quantity)
        ->and($scissors->quantity)->toBe(2)
        ->and($scissors->tag)->toBe('BDG-SML-0001');

    expect(fn () => ($this->register)('SML', ['tracking' => 'quantity', 'quantity' => 3], 2))
        ->toThrow(ValidationException::class, 'A counted group is one record')
        ->and(fn () => ($this->register)('SML', ['tracking' => 'quantity', 'quantity' => 0]))
        ->toThrow(ValidationException::class, 'holds at least one')
        ->and(fn () => ($this->register)('IT', ['serial_number' => 'SN1'], 2))
        ->toThrow(ValidationException::class, 'A serial number belongs to one item')
        ->and(fn () => ($this->register)('FURN', [], 51))
        ->toThrow(ValidationException::class, 'between 1 and 50');

    // An individual thing is always one, whatever quantity is sent.
    expect(($this->register)('IT', ['quantity' => 7])->sole()->quantity)->toBe(1);
});

it('puts a thing in exactly one active place of its own business', function () {
    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $foreignOffice = Office::query()->withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Elsewhere']);
    TenantScope::enter($this->business->id);
    $closed = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Closed', 'code' => 'CLSD', 'is_active' => false]);

    expect(fn () => ($this->register)('FURN', ['office_id' => null]))->toThrow(ValidationException::class, 'one office or one warehouse')
        ->and(fn () => ($this->register)('FURN', ['warehouse_id' => $this->warehouse->id]))->toThrow(ValidationException::class, 'one office or one warehouse')
        ->and(fn () => ($this->register)('FURN', ['office_id' => $foreignOffice->id]))->toThrow(ValidationException::class, 'not an active office or warehouse of this business')
        ->and(fn () => ($this->register)('FURN', ['office_id' => null, 'warehouse_id' => $closed->id]))->toThrow(ValidationException::class, 'not an active office or warehouse of this business');

    $kept = ($this->register)('FURN', ['office_id' => null, 'warehouse_id' => $this->warehouse->id])->sole();

    expect($kept->locationLabel())->toBe('Savar Warehouse');
});

it('makes a part belong to one tagged thing, never to itself or its own parts', function () {
    $desktop = ($this->register)('IT', ['name' => 'Desktop BordeGuna_Media'])->sole();
    $monitor = ($this->register)('IT', ['name' => 'Value Top 23.8 monitor', 'parent_id' => $desktop->id])->sole();
    $pool = ($this->register)('SML', ['name' => 'Rulers', 'tracking' => 'quantity', 'quantity' => 2])->sole();

    expect($monitor->parent_id)->toBe($desktop->id)
        ->and($desktop->parts()->pluck('id')->all())->toBe([$monitor->id]);

    $update = fn (AssetItem $item, array $overrides) => app(SaveAssetItem::class)->update($item, ($this->attributes)(['name' => $item->name] + $overrides), $this->owner);

    expect(fn () => $update($desktop, ['parent_id' => $monitor->id]))->toThrow(ValidationException::class, 'cannot be part of itself or of one of its own parts')
        ->and(fn () => $update($desktop, ['parent_id' => $desktop->id]))->toThrow(ValidationException::class, 'cannot be part of itself')
        ->and(fn () => ($this->register)('IT', ['parent_id' => $pool->id]))->toThrow(ValidationException::class, 'only be part of another tagged item')
        ->and(fn () => ($this->register)('SML', ['tracking' => 'quantity', 'quantity' => 1, 'parent_id' => $desktop->id]))->toThrow(ValidationException::class, 'A counted group cannot be part of');
});

it('lets a person set only in store, in use or under repair, and keeps a status a document set', function () {
    expect(fn () => ($this->register)('FURN', ['status' => 'missing']))->toThrow(ValidationException::class, 'Choose In store, In use or Under repair')
        ->and(fn () => ($this->register)('FURN', ['status' => 'disposed']))->toThrow(ValidationException::class, 'Choose In store, In use or Under repair')
        ->and(fn () => ($this->register)('FURN', ['condition' => 'shiny']))->toThrow(ValidationException::class, 'what condition');

    $chair = ($this->register)('FURN')->sole();
    $chair->forceFill(['status' => AssetStatus::Missing])->save();

    // Corrected by hand while missing: the status stays what the stock-take said.
    $saved = app(SaveAssetItem::class)->update($chair, ($this->attributes)(['status' => 'in_use', 'condition' => 'damaged', 'area' => 'Store room']), $this->owner);

    expect($saved->status)->toBe(AssetStatus::Missing)
        ->and($saved->condition)->toBe(AssetCondition::Damaged)
        ->and($saved->area)->toBe('Store room');

    expect(fn () => ($this->register)('FURN', ['acquired_on' => '2026-09-17']))->toThrow(ValidationException::class, 'in the future');
});

it('keeps prices from the Asset Keeper: not set, not shown, not cleared', function () {
    $this->actingAs($this->keeper)->post('/assets/items', ($this->form)())->assertRedirect();
    $laptop = AssetItem::query()->sole();

    expect($laptop->purchase_price)->toBeNull()
        ->and($laptop->tag)->toBe('BDG-IT-0001');

    $this->actingAs($this->owner)->put("/assets/items/{$laptop->id}", ($this->form)(['name' => 'ThinkPad T470s', 'purchase_price' => '45000']) + [])
        ->assertSessionHasErrors(['asset_category_id', 'tracking', 'copies']);

    $edit = collect(($this->form)())->except(['asset_category_id', 'tracking', 'copies'])->all();
    $this->actingAs($this->owner)->put("/assets/items/{$laptop->id}", $edit)->assertRedirect("/assets/items/{$laptop->id}");
    expect($laptop->fresh()->purchase_price)->toBe('45000.0000');

    $keeperView = $this->actingAs($this->keeper)->get("/assets/items/{$laptop->id}")->original->getData()['page']['props'];
    $ownerView = $this->actingAs($this->owner)->get("/assets/items/{$laptop->id}")->original->getData()['page']['props'];

    expect($keeperView['item']['purchase_price'])->toBeNull()
        ->and($ownerView['item']['purchase_price'])->toBe('45000.00')
        ->and($keeperView['item']['qr_svg'])->toContain('<svg')
        ->and($keeperView['item']['tag_url'])->toEndWith('/assets/items/t/BDG-IT-0001');

    // The keeper saves the laptop again with no price in the form: the price stays.
    $this->actingAs($this->keeper)->put("/assets/items/{$laptop->id}", array_replace(collect($edit)->except('purchase_price')->all(), ['area' => 'Studio']))->assertRedirect();
    expect($laptop->fresh()->purchase_price)->toBe('45000.0000')
        ->and($laptop->fresh()->area)->toBe('Studio');
});

it('opens the physical register to its keepers and the financial one only to Owner and Manager', function () {
    ($this->register)('FURN');

    $this->actingAs($this->staff)->get('/assets/items')->assertForbidden();
    $this->actingAs($this->staff)->get('/assets/items/create')->assertForbidden();

    $this->actingAs($this->keeper)->get('/assets/items')->assertOk();
    $this->actingAs($this->keeper)->get('/assets/items/create')->assertOk();
    $this->actingAs($this->keeper)->get('/assets/categories')->assertOk();
    $this->actingAs($this->keeper)->get('/assets')->assertForbidden();
    $this->actingAs($this->keeper)->get('/assets/depreciation')->assertForbidden();

    $manager = ($this->user)('Manager');
    $this->actingAs($manager)->get('/assets/items')->assertOk();
    $this->actingAs($manager)->get('/assets')->assertOk();

    // ⚠ Someone who may only LOOK at the register changes nothing in it.
    Role::create(['name' => 'Register reader', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('assets.items.view');
    $reader = ($this->user)('Register reader');
    $chair = AssetItem::query()->firstOrFail();

    $this->actingAs($reader)->get("/assets/items/{$chair->id}")->assertOk();
    $this->actingAs($reader)->get('/assets/items/create')->assertForbidden();
    $this->actingAs($reader)->post('/assets/items', ($this->form)())->assertForbidden();
    $this->actingAs($reader)->get("/assets/items/{$chair->id}/edit")->assertForbidden();
    $this->actingAs($reader)->put("/assets/items/{$chair->id}", ($this->form)())->assertForbidden();
    $this->actingAs($reader)->post("/assets/items/{$chair->id}/photos", ['kind' => 'identity'])->assertForbidden();
    $this->actingAs($reader)->post('/assets/categories', ['name' => 'X', 'code' => 'XX', 'default_tracking' => 'individual'])->assertForbidden();
    expect(AssetItem::query()->count())->toBe(1);

    $shared = $this->actingAs($this->keeper)->get('/assets/items')->original->getData()['page']['props'];
    expect($shared['canViewAssetItems'])->toBeTrue()
        ->and($shared['canViewAssets'])->toBeFalse()
        ->and($shared['items']['data'][0]['tag'])->toBe('BDG-FURN-0001');
});

it('opens a scanned tag and changes nothing, and names the business of a tag kept elsewhere', function () {
    $chair = ($this->register)('FURN')->sole();
    $before = Activity::query()->count();

    $this->actingAs($this->keeper)->get('/assets/items/t/BDG-FURN-0001')->assertRedirect("/assets/items/{$chair->id}");
    $this->actingAs($this->keeper)->get("/assets/items/{$chair->id}")->assertOk();

    expect(Activity::query()->count())->toBe($before)
        ->and($chair->fresh()->updated_at->equalTo($chair->updated_at))->toBeTrue();

    // A tag of LaLaDia, where this keeper also works.
    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    (new AssetsPermissionsSeeder)->run();
    (new AssetCategorySeeder)->run();
    TenantScope::enter($other->id);
    $foreignOffice = Office::create(['business_id' => $other->id, 'name' => 'Shop office']);
    $theirs = app(SaveAssetItem::class)->register(AssetCategory::query()->where('code', 'CAM')->sole(), ($this->attributes)(['office_id' => $foreignOffice->id]), $this->owner)->sole();
    TenantScope::enter($this->business->id);

    // ⚠ Not one of this keeper's businesses (yet): nothing about it is said.
    $this->actingAs($this->keeper)->get('/assets/items/t/LAL-CAM-0001')->assertNotFound();

    $this->keeper->businesses()->attach($other->id);

    expect($theirs->tag)->toBe('LAL-CAM-0001');

    $this->actingAs($this->keeper)->get('/assets/items/t/LAL-CAM-0001')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('error', 'LAL-CAM-0001 belongs to LaLaDia. Switch to LaLaDia and scan it again.');
    $this->actingAs($this->keeper)->get("/assets/items/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->keeper)->get('/assets/items/t/BDG-FURN-9999')->assertNotFound();
});

it('refuses a tag another business of the group already holds', function () {
    // Two slugs with the same initials.
    $twin = Business::create(['name' => 'Bangla Dairy Group', 'slug' => 'bangla-dairy-group']);
    (new AssetCategorySeeder)->run();
    TenantScope::enter($twin->id);
    $twinOffice = Office::create(['business_id' => $twin->id, 'name' => 'Dairy office']);
    app(SaveAssetItem::class)->register(AssetCategory::query()->where('code', 'FURN')->sole(), ($this->attributes)(['office_id' => $twinOffice->id]), $this->owner);
    TenantScope::enter($this->business->id);

    expect(AssetTag::prefixFor($twin))->toBe('BDG')
        ->and(fn () => ($this->register)('FURN'))->toThrow(ValidationException::class, 'Tag BDG-FURN-0001 is already used by another of the group\'s businesses');
});

it('keeps photos of a thing for its keepers, and removes only a photo of that thing', function () {
    $chair = ($this->register)('FURN')->sole();
    $table = ($this->register)('FURN', ['name' => 'Table'])->sole();

    $this->actingAs($this->keeper)->post("/assets/items/{$chair->id}/photos", [
        'kind' => 'identity',
        'photos' => [UploadedFile::fake()->image('chair.jpg', 300, 300)],
    ])->assertRedirect();

    $photo = Attachment::query()->sole();

    expect($photo->collection)->toBe('identity')
        ->and($photo->attachable_id)->toBe($chair->id);

    $this->actingAs($this->keeper)->get("/attachments/{$photo->id}")->assertOk();
    $this->actingAs($this->staff)->get("/attachments/{$photo->id}")->assertForbidden();

    // Hand-over photos belong to their documents, not to a loose upload.
    $this->actingAs($this->keeper)->post("/assets/items/{$chair->id}/photos", [
        'kind' => 'handover',
        'photos' => [UploadedFile::fake()->image('x.jpg')],
    ])->assertSessionHasErrors('kind');

    $this->actingAs($this->keeper)->delete("/assets/items/{$table->id}/photos/{$photo->id}")->assertNotFound();
    $photo->forceFill(['collection' => 'handover'])->save();
    $this->actingAs($this->keeper)->delete("/assets/items/{$chair->id}/photos/{$photo->id}")->assertNotFound();
    $photo->forceFill(['collection' => 'identity'])->save();
    $this->actingAs($this->keeper)->delete("/assets/items/{$chair->id}/photos/{$photo->id}")->assertRedirect();

    expect(Attachment::query()->count())->toBe(0);
});

it('writes what changed in words a person reads', function () {
    $chair = ($this->register)('FURN')->sole();

    app(SaveAssetItem::class)->update($chair, ($this->attributes)(['office_id' => null, 'warehouse_id' => $this->warehouse->id, 'condition' => 'damaged']), $this->owner);

    $history = $this->actingAs($this->owner)->get("/assets/items/{$chair->id}")->original->getData()['page']['props']['history'];
    $changes = collect($history[0]['changes'])->keyBy('field');

    expect($changes['Office']['from'])->toBe('Corporate Office - Adabor 2')
        ->and($changes['Office']['to'])->toBeNull()
        ->and($changes['Warehouse']['to'])->toBe('Savar Warehouse')
        ->and($changes['Condition']['from'])->toBe('Good')
        ->and($changes['Condition']['to'])->toBe('Damaged');
});

it('fixes a category code once a tag carries it, and seeds categories only into an empty list', function () {
    $furniture = ($this->category)('FURN');

    $this->actingAs($this->keeper)->put("/assets/categories/{$furniture->id}", ['name' => 'Furniture', 'code' => 'FUR', 'default_tracking' => 'individual'])
        ->assertRedirect();
    expect($furniture->fresh()->code)->toBe('FUR');

    ($this->register)('FUR');

    $this->actingAs($this->keeper)->put("/assets/categories/{$furniture->id}", ['name' => 'Chairs and tables', 'code' => 'CHR', 'default_tracking' => 'individual'])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->keeper)->post('/assets/categories', ['name' => 'Kitchen', 'code' => 'IT', 'default_tracking' => 'individual'])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->keeper)->post('/assets/categories', ['name' => 'Kitchen', 'code' => 'kit', 'default_tracking' => 'individual'])
        ->assertRedirect();
    $this->actingAs($this->staff)->post('/assets/categories', ['name' => 'Tools2', 'code' => 'TL', 'default_tracking' => 'individual'])
        ->assertForbidden();

    // A retired, renamed list is the business's own: the seeder leaves it alone.
    ($this->category)('VEH')->update(['name' => 'Bikes', 'is_active' => false]);
    (new AssetCategorySeeder)->run();
    TenantScope::enter($this->business->id);

    expect(AssetCategory::query()->where('name', 'Vehicles')->exists())->toBeFalse()
        ->and(AssetCategory::query()->where('code', 'KIT')->exists())->toBeTrue()
        // 12 seeded (MACH and LHI since 29 Sep 2026) + KIT.
        ->and(AssetCategory::query()->count())->toBe(13);

    expect(fn () => ($this->register)('VEH'))->toThrow(ValidationException::class, 'is switched off');
});

it('depreciates only an asset whose cost the books carry, and says which it skipped', function () {
    // An opening asset is depreciated from the cutover (FEA-004, 14 Oct 2026);
    // this business's cutover is put at July so July and August are charged.
    config(['accounting.cutover_date' => '2026-07-01']);
    $bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);
    $record = fn (string $code, ?MoneyAccount $paidFrom, bool $opening = false) => app(RecordFixedAsset::class)->handle(
        assetCode: $code, name: $code, assetAccount: Account::where('code', '1220')->sole(),
        acquiredOn: '2026-07-01', cost: '120000', usefulLifeMonths: 12, createdBy: $this->owner,
        moneyAccount: $paidFrom, costInOpeningBalances: $opening,
    );

    $paid = $record('PAID', $bank);
    $opening = $record('OPEN', null, true);
    $owned = $record('OWNED', null);

    expect($paid->cost_on_books)->toBe(FixedAsset::COST_BY_ACQUISITION)
        ->and($opening->cost_on_books)->toBe(FixedAsset::COST_IN_OPENING_BALANCES)
        ->and($owned->cost_on_books)->toBeNull()
        ->and(fn () => $record('BOTH', $bank, true))->toThrow(ValidationException::class, 'not also in the opening balances');

    $result = app(ChargeDepreciation::class)->handle(2026, 8, $this->owner);

    expect($result['charged'])->toBe(2)
        ->and($result['not_on_books'])->toBe(['OWNED'])
        ->and($owned->fresh()->depreciations()->count())->toBe(0)
        ->and($owned->fresh()->isDepreciableIn(2026, 8))->toBeFalse();

    $this->actingAs($this->owner)->post('/assets/depreciation', ['year' => 2026, 'month' => 7])
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Not depreciated because their cost is not in the books: OWNED.'));
});

it('will not delete an office or a warehouse where things are kept', function () {
    ($this->register)('FURN');
    ($this->register)('FURN', ['office_id' => null, 'warehouse_id' => $this->warehouse->id]);
    $this->warehouse->update(['is_default' => false]);

    $admin = ($this->user)('Owner');
    setPermissionsTeamId($this->business->id);
    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    $admin->givePermissionTo('foundation.organization.manage');
    Permission::firstOrCreate(['name' => 'inventory.catalog.manage', 'guard_name' => 'web']);
    $admin->givePermissionTo('inventory.catalog.manage');

    $this->actingAs($admin)->delete("/settings/organization/offices/{$this->office->id}")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'kept at this office'));
    $this->actingAs($admin)->delete("/inventory/warehouses/{$this->warehouse->id}")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'kept in this warehouse'));

    expect(Office::query()->whereKey($this->office->id)->exists())->toBeTrue()
        ->and(Warehouse::query()->whereKey($this->warehouse->id)->exists())->toBeTrue();
});

it('prints tags only for this business\'s things, each with its code', function () {
    $chairs = ($this->register)('FURN', [], 2);
    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    (new AssetCategorySeeder)->run();
    TenantScope::enter($other->id);
    $foreignOffice = Office::create(['business_id' => $other->id, 'name' => 'Shop office']);
    $theirs = app(SaveAssetItem::class)->register(AssetCategory::query()->where('code', 'CAM')->sole(), ($this->attributes)(['office_id' => $foreignOffice->id]), $this->owner)->sole();
    TenantScope::enter($this->business->id);

    $props = $this->actingAs($this->keeper)
        ->get('/assets/items/labels/print?'.http_build_query(['ids' => [...$chairs->pluck('id')->all(), $theirs->id], 'size' => 'small', 'copies' => 2]))
        ->assertOk()->original->getData()['page']['props'];

    expect(collect($props['labels'])->pluck('tag')->all())->toBe(['BDG-FURN-0001', 'BDG-FURN-0001', 'BDG-FURN-0002', 'BDG-FURN-0002'])
        ->and($props['labels'][0]['svg'])->toStartWith('<svg')
        ->and($props['size']['qr'])->toBe(17);

    $this->actingAs($this->keeper)->get('/assets/items/labels/print?'.http_build_query(['ids' => [$chairs->first()->id], 'size' => 'huge']))
        ->assertSessionHasErrors('size');
});

// "Add a thing" and "Edit" are popups since 13 Oct 2026: the list and the
// thing's page carry the form's choices under `form`, the thing's page carries
// the values under `editing`, and the old create and edit addresses still open.
it('carries the popup form on the list and the thing\'s page, and saves through the same routes', function () {
    $chair = ($this->register)('FURN')->first();

    $this->actingAs($this->keeper)->get('/assets/items')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('assets/items/index')
            ->where('can_manage', true)
            ->has('form.categories')
            ->has('form.places', 2)
            ->has('form.statuses')
            ->has('form.conditions')
            ->has('form.trackings')
            ->where('form.max_copies', SaveAssetItem::MAX_COPIES)
            // ⚠ The Asset Keeper keeps the things, not the prices.
            ->where('form.sees_price', false));

    $this->actingAs($this->owner)->get("/assets/items/{$chair->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('assets/items/show')
            ->has('form.categories')
            ->where('form.sees_price', true)
            ->where('editing.id', $chair->id)
            ->where('editing.tag', $chair->tag)
            ->where('editing.place', 'office:'.$this->office->id)
            ->where('editing.status_is_settable', true));

    // Someone who may only look gets the thing, not the form.
    Role::create(['name' => 'Looker', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('assets.items.view');
    $looker = ($this->user)('Looker');
    $this->actingAs($looker)->get('/assets/items')->assertInertia(fn (Assert $page) => $page->where('form', null));
    $this->actingAs($looker)->get("/assets/items/{$chair->id}")->assertInertia(fn (Assert $page) => $page->where('form', null)->where('editing', null));

    // The old addresses still open, carrying the same props as the popup.
    $this->actingAs($this->keeper)->get('/assets/items/create')
        ->assertInertia(fn (Assert $page) => $page->component('assets/items/form')->where('item', null)->has('form.categories'));
    $this->actingAs($this->keeper)->get("/assets/items/{$chair->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->component('assets/items/form')->where('item.tag', $chair->tag)->has('form.parents'));

    // The popup writes through the same routes.
    $this->actingAs($this->owner)->post('/assets/items', ($this->form)())->assertRedirect();
    $laptop = AssetItem::query()->where('name', 'ThinkPad T470s')->sole();

    $this->actingAs($this->owner)->put("/assets/items/{$laptop->id}", [
        'name' => 'ThinkPad T470s (studio)',
        'place' => 'office:'.$this->office->id,
        'area' => 'Studio',
        'status' => 'in_use',
        'condition' => 'good',
    ])->assertRedirect("/assets/items/{$laptop->id}");

    expect($laptop->fresh()->area)->toBe('Studio');
});
