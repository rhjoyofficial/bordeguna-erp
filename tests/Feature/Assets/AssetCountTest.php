<?php

use App\Models\User;
use App\Modules\Assets\Actions\CountAssets;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\DiscrepancyKind;
use App\Modules\Assets\Enums\DiscrepancyResolution;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetDiscrepancy;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset management slice A3: a stock-take of one place, and the gaps it finds.
 * Plan §4d.
 *
 * ⭐ The list is what the register expected when the count started; only an
 * explicit mark counts; closing writes every gap down; confirming a loss is
 * the Owner's or a Manager's decision.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-17 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    (new AssetCategorySeeder)->run();
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->keeper = ($this->user)('Asset Keeper');
    $this->manager = ($this->user)('Manager');
    $this->staff = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);

    $this->rakib = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Rakib', 'last_name' => 'Test', 'employment_type' => 'permanent',
        'status' => 'active', 'joining_date' => '2026-01-01',
    ]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->rakib->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);
    $this->shop = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'kind' => 'repair_shop']);

    $this->register = fn (string $code, string $name, array $overrides = []) => app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', $code)->sole(),
        array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
        $this->keeper,
    )->first();

    $this->laptop = ($this->register)('IT', 'ThinkPad');
    $this->camera = ($this->register)('CAM', 'Canon R50');
    $this->printer = ($this->register)('PRN', 'LaserJet');
    $this->chair = ($this->register)('FURN', 'Desk chair');
    $this->scissors = ($this->register)('SML', 'Scissors', ['tracking' => 'quantity', 'quantity' => 5]);
    $this->shelfFan = ($this->register)('OFF', 'Fan', ['office_id' => null, 'warehouse_id' => $this->warehouse->id]);

    $this->counts = app(CountAssets::class);
    $this->start = fn (?User $by = null) => $this->counts->start($this->business, ['office_id' => $this->office->id], $by ?? $this->keeper);
    $this->lineOf = fn (AssetCount $count, AssetItem $item) => $count->lines()->where('asset_item_id', $item->id)->first();
});

afterEach(fn () => Carbon::setTestNow());

it('lists what the register expects at the place, as it stood when the count started', function () {
    // With an employee: still on the list. With a repair shop, or on its way: not.
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper);
    app(MoveAssets::class)->handle(AssetMovementKind::SendOut, [['item' => $this->printer]], ['party_id' => $this->shop->id], $this->keeper);
    app(MoveAssets::class)->handle(AssetMovementKind::Transfer, [['item' => $this->chair]], ['warehouse_id' => $this->warehouse->id], $this->keeper);
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->scissors, 'quantity' => 2]], ['employee_id' => $this->rakib->id], $this->keeper);
    $this->camera->forceFill(['status' => AssetStatus::Disposed])->save();

    $count = ($this->start)();

    expect($count->number)->toBe('AC-000001')
        ->and($count->lines->pluck('expected_quantity', 'asset_item_id')->all())->toBe([$this->laptop->id => 1, $this->scissors->id => 3])
        ->and($count->lines->every(fn ($line) => $line->expected && $line->found === null))->toBeTrue();

    // A hand-over during the count does not change the list.
    app(MoveAssets::class)->handle(AssetMovementKind::Return, [['item' => $this->laptop]], ['warehouse_id' => $this->warehouse->id], $this->keeper);
    expect($count->fresh()->lines()->count())->toBe(2);

    // One open count per place; another place is fine.
    expect(fn () => ($this->start)())->toThrow(ValidationException::class, 'A count of that place is already open')
        ->and($this->counts->start($this->business, ['warehouse_id' => $this->warehouse->id], $this->keeper)->number)->toBe('AC-000002')
        ->and(fn () => $this->counts->start($this->business, ['office_id' => $this->office->id, 'warehouse_id' => $this->warehouse->id], $this->keeper))
        ->toThrow(ValidationException::class, 'one office or one warehouse');
});

it('closes only when every expected thing is marked, and writes down each gap', function () {
    $count = ($this->start)();
    $this->laptop->forceFill(['status' => AssetStatus::InUse])->save();

    $this->counts->mark($count, $this->laptop, false, $this->keeper);
    $this->counts->mark($count, $this->camera, true, $this->keeper, null, 'poor');
    $this->counts->mark($count, $this->printer, true, $this->keeper);

    expect(fn () => $this->counts->close($count, $this->keeper))->toThrow(ValidationException::class, '2 still to mark: BDG-FURN-0001, BDG-SML-0001');
    expect(fn () => $this->counts->mark($count, $this->scissors, true, $this->keeper))->toThrow(ValidationException::class, 'Say how many pieces');

    $this->counts->mark($count, $this->chair, true, $this->keeper);
    $this->counts->mark($count, $this->scissors, true, $this->keeper, 3);
    // Something that lives at the warehouse, found in the office.
    $this->counts->addFound($count, $this->shelfFan, $this->keeper);
    expect(fn () => $this->counts->addFound($count, $this->camera, $this->keeper))->toThrow(ValidationException::class, 'already on AC-000001')
        ->and(fn () => $this->counts->mark($count, ($this->register)('IT', 'Mouse'), true, $this->keeper))->toThrow(ValidationException::class, 'is not on AC-000001');

    // The fan is not "expected", so closing does not wait for anything else.
    $closed = $this->counts->close($count, $this->keeper);
    $gaps = AssetDiscrepancy::query()->orderBy('id')->get();

    expect($closed->status)->toBe('closed')->and($closed->open_place_key)->toBeNull()
        ->and($gaps->map(fn ($g) => $g->kind->value.':'.$g->item->tag.':'.$g->quantity)->all())->toBe(['missing:BDG-IT-0001:1', 'short:BDG-SML-0001:2', 'unexpected:BDG-OFF-0001:1'])
        ->and($gaps[0]->status_before)->toBe('in_use')
        ->and($gaps->every(fn ($g) => $g->responsible_user_id === $this->keeper->id && $g->isOpen()))->toBeTrue()
        ->and($this->laptop->fresh()->status)->toBe(AssetStatus::Missing)
        ->and($this->camera->fresh()->condition)->toBe(AssetCondition::Poor)
        ->and($this->printer->fresh()->condition)->toBe(AssetCondition::Good);

    // A missing thing does not move; the closed count takes no more marks; the place may be counted again.
    expect(fn () => app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper))
        ->toThrow(ValidationException::class, 'is Missing')
        ->and(fn () => $this->counts->mark($count, $this->camera, false, $this->keeper))->toThrow(ValidationException::class, 'AC-000001 is closed')
        ->and(($this->start)()->number)->toBe('AC-000002');
});

it('settles each gap as its kind allows, and only a Manager or Owner confirms a loss', function () {
    $count = ($this->start)();
    $this->counts->mark($count, $this->laptop, false, $this->keeper);
    $this->counts->mark($count, $this->camera, false, $this->keeper);
    $this->counts->mark($count, $this->printer, false, $this->keeper);
    $this->counts->mark($count, $this->chair, true, $this->keeper);
    $this->counts->mark($count, $this->scissors, true, $this->keeper, 2);
    $this->counts->close($count, $this->keeper);

    $gap = fn (AssetItem $item) => AssetDiscrepancy::query()->where('asset_item_id', $item->id)->sole();

    // Located: status back. Found damaged: condition Damaged, status back.
    $this->counts->resolve($gap($this->laptop), DiscrepancyResolution::Located, $this->keeper, 'Was in the drawer');
    $this->counts->resolve($gap($this->camera), DiscrepancyResolution::FoundDamaged, $this->keeper);

    expect($this->laptop->fresh()->status)->toBe(AssetStatus::InStore)
        ->and($this->camera->fresh()->status)->toBe(AssetStatus::InStore)
        ->and($this->camera->fresh()->condition)->toBe(AssetCondition::Damaged)
        ->and($gap($this->laptop)->resolution)->toBe(DiscrepancyResolution::Located)
        ->and($gap($this->laptop)->note)->toBe('Was in the drawer')
        ->and(fn () => $this->counts->resolve($gap($this->laptop), DiscrepancyResolution::Located, $this->keeper))->toThrow(ValidationException::class, 'already settled');

    // A kind's own choices only; a loss is not the keeper's to confirm.
    expect(fn () => $this->counts->resolve($gap($this->scissors), DiscrepancyResolution::FoundDamaged, $this->keeper))->toThrow(ValidationException::class, 'does not settle')
        ->and(fn () => $this->counts->resolve($gap($this->printer), DiscrepancyResolution::ConfirmedLost, $this->keeper))->toThrow(ValidationException::class, 'Only the Owner or a Manager');

    $this->counts->resolve($gap($this->printer), DiscrepancyResolution::ConfirmedLost, $this->manager);
    expect($this->printer->fresh()->status)->toBe(AssetStatus::Missing);

    // Three scissors confirmed lost: the group is counted fewer, at the place.
    $this->counts->resolve($gap($this->scissors), DiscrepancyResolution::ConfirmedLost, $this->manager);
    expect($this->scissors->fresh()->quantity)->toBe(2)
        ->and(AssetHolding::query()->where('asset_item_id', $this->scissors->id)->pluck('quantity', 'holder_key')->all())->toBe(["o{$this->office->id}" => 2]);
    $this->artisan('assets:rebuild-holdings')->assertSuccessful();
});

it('adds pieces found over the count, and closes an earlier gap when the thing turns up', function () {
    $first = ($this->start)();
    foreach ([$this->laptop, $this->camera, $this->printer, $this->chair] as $item) {
        $this->counts->mark($first, $item, $item->isNot($this->laptop), $this->keeper);
    }
    $this->counts->mark($first, $this->scissors, true, $this->keeper, 7);
    $this->counts->close($first, $this->keeper);

    $over = AssetDiscrepancy::query()->where('kind', DiscrepancyKind::Over->value)->sole();
    expect($over->quantity)->toBe(2);
    $this->counts->resolve($over, DiscrepancyResolution::Located, $this->keeper);
    expect($this->scissors->fresh()->quantity)->toBe(7)
        ->and(AssetHolding::query()->where('asset_item_id', $this->scissors->id)->value('quantity'))->toBe(7);

    // The laptop is missing; the next count finds it, and its gap closes itself.
    $second = ($this->start)();
    expect(($this->lineOf)($second, $this->laptop))->not->toBeNull();
    foreach ([$this->laptop, $this->camera, $this->printer, $this->chair] as $item) {
        $this->counts->mark($second, $item, true, $this->keeper);
    }
    $this->counts->mark($second, $this->scissors, true, $this->keeper, 7);
    $this->counts->close($second, $this->keeper);

    $missing = AssetDiscrepancy::query()->where('kind', DiscrepancyKind::Missing->value)->sole();
    expect($missing->isOpen())->toBeFalse()
        ->and($missing->resolution)->toBe(DiscrepancyResolution::Located)
        ->and($missing->note)->toBe('Found in AC-000002.')
        ->and($this->laptop->fresh()->status)->toBe(AssetStatus::InStore)
        ->and(AssetDiscrepancy::query()->where('asset_count_id', $second->id)->count())->toBe(0);
});

it('runs through the screens for the keeper, and marks nothing by opening a page', function () {
    $this->actingAs($this->staff)->get('/assets/counts')->assertForbidden();
    $this->actingAs($this->staff)->post('/assets/counts', ['place' => "office:{$this->office->id}"])->assertForbidden();

    $this->actingAs($this->keeper)->post('/assets/counts', ['place' => "office:{$this->office->id}"])->assertRedirect('/assets/counts/'.AssetCount::query()->sole()->id);
    $count = AssetCount::query()->sole();

    // The item page offers the mark; opening it (a QR scan) marks nothing.
    $item = $this->actingAs($this->keeper)->get("/assets/items/{$this->laptop->id}")->original->getData()['page']['props']['item'];
    expect($item['open_count'])->toMatchArray(['number' => 'AC-000001', 'on_list' => true, 'found' => null])
        ->and(($this->lineOf)($count, $this->laptop)->found)->toBeNull();

    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/mark", ['item_id' => $this->laptop->id, 'found' => true, 'condition' => 'fair'])->assertRedirect();
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/found", ['tag' => 'bdg-off-0001'])->assertRedirect();
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/found", ['tag' => 'BDG-XX-9999'])->assertSessionHasErrors('tag');
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/close")->assertSessionHasErrors('count');

    foreach ([$this->camera, $this->printer, $this->chair] as $thing) {
        $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/mark", ['item_id' => $thing->id, 'found' => true])->assertRedirect();
    }
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/mark", ['item_id' => $this->scissors->id, 'found' => true, 'pieces' => 5])->assertRedirect();
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/close")->assertRedirect();

    $show = $this->actingAs($this->keeper)->get("/assets/counts/{$count->id}")->assertOk()->original->getData()['page']['props'];
    expect($show['count']['status'])->toBe('closed')
        ->and($show['gaps'])->toHaveCount(1)
        ->and($show['gaps'][0]['kind'])->toBe('unexpected')
        ->and(collect($show['gaps'][0]['choices'])->pluck('value')->all())->toBe(['located', 'wrongly_recorded']);

    // The keeper is not offered a loss to confirm; the Manager is.
    $this->actingAs($this->keeper)->post('/assets/counts', ['place' => "office:{$this->office->id}"]);
    $second = AssetCount::query()->latest('id')->first();
    $this->counts->mark($second, $this->laptop, false, $this->keeper);
    foreach ([$this->camera, $this->printer, $this->chair] as $thing) {
        $this->counts->mark($second, $thing, true, $this->keeper);
    }
    $this->counts->mark($second, $this->scissors, true, $this->keeper, 5);
    $this->counts->close($second, $this->keeper);

    $choices = fn (User $as) => collect($this->actingAs($as)->get('/assets/counts')->original->getData()['page']['props']['gaps'])
        ->firstWhere('kind', 'missing')['choices'];
    expect(collect($choices($this->keeper))->pluck('value')->all())->toBe(['located', 'wrongly_recorded', 'found_damaged'])
        ->and(collect($choices($this->manager))->pluck('value')->all())->toContain('confirmed_lost');

    $missing = AssetDiscrepancy::query()->where('kind', 'missing')->sole();
    $this->actingAs($this->keeper)->post("/assets/discrepancies/{$missing->id}/resolve", ['resolution' => 'confirmed_lost'])->assertSessionHasErrors('resolution');
    $this->actingAs($this->manager)->post("/assets/discrepancies/{$missing->id}/resolve", ['resolution' => 'confirmed_lost', 'note' => 'Stolen'])->assertRedirect();
    expect($missing->fresh()->resolution)->toBe(DiscrepancyResolution::ConfirmedLost);

    $work = $this->actingAs($this->keeper)->get('/work')->original->getData()['page']['props']['assets'];
    expect($work['open_gaps'])->toBe(1);
});

it('keeps counts and gaps within their business', function () {
    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    (new AssetCategorySeeder)->run();
    TenantScope::enter($other->id);
    $foreignOffice = Office::create(['business_id' => $other->id, 'name' => 'Shop office']);
    $theirs = app(SaveAssetItem::class)->register(AssetCategory::query()->where('code', 'CAM')->sole(), ['name' => 'Theirs', 'office_id' => $foreignOffice->id, 'status' => 'in_store', 'condition' => 'good'], $this->keeper)->sole();
    TenantScope::enter($this->business->id);

    expect(fn () => $this->counts->start($this->business, ['office_id' => $foreignOffice->id], $this->keeper))
        ->toThrow(ValidationException::class, 'not an active office or warehouse of this business');

    $count = ($this->start)();
    expect(fn () => $this->counts->addFound($count, $theirs, $this->keeper))->toThrow(ValidationException::class, 'not in this business');

    // A thing already disposed of cannot turn up on a count.
    $this->shelfFan->forceFill(['status' => AssetStatus::Disposed])->save();
    expect(fn () => $this->counts->addFound($count, $this->shelfFan, $this->keeper))->toThrow(ValidationException::class, 'was disposed of');
    $this->actingAs($this->keeper)->post("/assets/counts/{$count->id}/found", ['tag' => 'LAL-CAM-0001'])->assertSessionHasErrors('tag');

    TenantScope::enter($other->id);
    $foreignCount = app(CountAssets::class)->start($other, ['office_id' => $foreignOffice->id], $this->keeper);
    TenantScope::enter($this->business->id);
    $this->actingAs($this->keeper)->get("/assets/counts/{$foreignCount->id}")->assertNotFound();
    $this->actingAs($this->keeper)->post("/assets/counts/{$foreignCount->id}/close")->assertNotFound();
});
