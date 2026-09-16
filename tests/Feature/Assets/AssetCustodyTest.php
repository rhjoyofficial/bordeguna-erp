<?php

use App\Models\User;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset management slice A2: who has a thing, and every hand-over that put it
 * there. Plan §4c.
 *
 * ⭐ A thing moves only from where the register says it is; a movement is never
 * edited; a transfer is at neither place until received; the holdings of a
 * counted group always add up to its movements.
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
    $this->staff = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);

    $this->employee = function (string $first, ?User $user = null): Employee {
        $employee = Employee::create([
            'employee_code' => 'BDG-'.str_pad((string) (Employee::query()->withoutGlobalScopes()->count() + 1), 6, '0', STR_PAD_LEFT),
            'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active',
            'joining_date' => '2026-01-01', 'user_id' => $user?->id,
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id, 'employee_id' => $employee->id, 'office_id' => $this->office->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $employee;
    };

    $this->rakib = ($this->employee)('Rakib', $this->staff);
    $this->nazmul = ($this->employee)('Nazmul');
    $this->shop = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'organisation' => 'Rahim Computer Care', 'kind' => 'repair_shop']);
    $this->freelancer = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Mitu', 'kind' => 'freelancer']);

    $this->register = fn (string $code, string $name, array $overrides = []) => app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', $code)->sole(),
        array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
        $this->keeper,
    )->first();

    $this->laptop = ($this->register)('IT', 'ThinkPad T470s');
    $this->scissors = ($this->register)('SML', 'Steel scissors', ['tracking' => 'quantity', 'quantity' => 4]);

    $this->move = fn (AssetMovementKind $kind, array $lines, array $to, ?string $on = '2026-09-16') => app(MoveAssets::class)
        ->handle($kind, $lines, $to, $this->keeper, $on);

    $this->holdings = fn (AssetItem $item) => AssetHolding::query()->where('asset_item_id', $item->id)->orderBy('holder_key')->pluck('quantity', 'holder_key')->all();
});

afterEach(fn () => Carbon::setTestNow());

it('hands a thing to an employee, who acknowledges it, and takes it back', function () {
    $issue = ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop, 'condition' => 'good']], ['employee_id' => $this->rakib->id]);
    $laptop = $this->laptop->fresh();

    expect($issue->number)->toBe('AM-000001')
        ->and($issue->status)->toBe(AssetMovementStatus::Completed)
        ->and($issue->fromLabel())->toBe('Corporate Office - Adabor 2')
        ->and($issue->toLabel())->toBe('Rakib Test')
        ->and($laptop->custodian_employee_id)->toBe($this->rakib->id)
        ->and($laptop->status)->toBe(AssetStatus::InUse)
        ->and($laptop->office_id)->toBe($this->office->id)
        ->and($laptop->custody_since->toDateString())->toBe('2026-09-16')
        ->and($laptop->acknowledged_at)->toBeNull();

    // Rakib sees it in My assets and says he has it; nobody else can.
    $mine = $this->actingAs($this->staff)->get('/hrm/my-assets')->assertOk()->original->getData()['page']['props']['items'];
    expect($mine)->toHaveCount(1)->and($mine[0]['tag'])->toBe('BDG-IT-0001');

    $this->actingAs($this->keeper)->post("/hrm/my-assets/{$laptop->id}/acknowledge")->assertNotFound();
    // Another employee cannot say they have Rakib's laptop.
    $other = ($this->user)('Staff');
    $this->nazmul->forceFill(['user_id' => $other->id])->save();
    $this->actingAs($other)->post("/hrm/my-assets/{$laptop->id}/acknowledge")->assertNotFound();
    expect($laptop->fresh()->acknowledged_at)->toBeNull()
        ->and($this->actingAs($other)->get('/hrm/my-assets')->original->getData()['page']['props']['items'])->toHaveCount(0);
    $this->actingAs($this->staff)->post("/hrm/my-assets/{$laptop->id}/acknowledge")->assertRedirect();
    expect($laptop->fresh()->acknowledged_at)->not->toBeNull();

    // Issued again while held: refused. Returned to the warehouse: nobody's, in store.
    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'is with Rakib Test. Take it back first');

    $return = ($this->move)(AssetMovementKind::Return, [['item' => $laptop, 'condition' => 'fair']], ['warehouse_id' => $this->warehouse->id]);
    $laptop = $laptop->fresh();

    expect($return->number)->toBe('AM-000002')
        ->and($return->fromLabel())->toBe('Rakib Test')
        ->and($laptop->custodian_employee_id)->toBeNull()
        ->and($laptop->status)->toBe(AssetStatus::InStore)
        ->and($laptop->condition)->toBe(AssetCondition::Fair)
        ->and($laptop->warehouse_id)->toBe($this->warehouse->id)
        ->and($laptop->office_id)->toBeNull()
        ->and($laptop->acknowledged_at)->toBeNull();

    expect(fn () => ($this->move)(AssetMovementKind::Return, [['item' => $laptop]], ['office_id' => $this->office->id]))
        ->toThrow(ValidationException::class, 'is not with an employee');
});

it('sends a thing to a repair shop and receives it back', function () {
    // Nothing to receive back from a thing nobody outside holds.
    expect(fn () => ($this->move)(AssetMovementKind::ReceiveBack, [['item' => $this->laptop]], ['office_id' => $this->office->id]))
        ->toThrow(ValidationException::class, 'is not with an outside party');

    ($this->move)(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->shop->id]);
    expect($this->laptop->fresh()->status)->toBe(AssetStatus::UnderRepair)
        ->and($this->laptop->fresh()->custodian_party_id)->toBe($this->shop->id)
        ->and($this->laptop->fresh()->custodyLabel())->toBe('Rahim (Rahim Computer Care)');

    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is with Rahim (Rahim Computer Care)');

    ($this->move)(AssetMovementKind::ReceiveBack, [['item' => $this->laptop]], ['office_id' => $this->office->id]);
    expect($this->laptop->fresh()->status)->toBe(AssetStatus::InStore)->and($this->laptop->fresh()->custodian_party_id)->toBeNull();

    ($this->move)(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->freelancer->id]);
    expect($this->laptop->fresh()->status)->toBe(AssetStatus::WithOutsideParty);

    $this->shop->update(['is_active' => false]);
    expect(fn () => ($this->move)(AssetMovementKind::SendOut, [['item' => ($this->register)('IT', 'Mouse')]], ['party_id' => $this->shop->id]))
        ->toThrow(ValidationException::class, 'Choose an outside party of this business');
});

it('moves a thing to another place in two steps, and it is at neither place between', function () {
    $transfer = ($this->move)(AssetMovementKind::Transfer, [['item' => $this->laptop]], ['warehouse_id' => $this->warehouse->id]);
    $laptop = $this->laptop->fresh();

    expect($transfer->status)->toBe(AssetMovementStatus::Dispatched)
        ->and($laptop->transit_movement_id)->toBe($transfer->id)
        ->and($laptop->office_id)->toBe($this->office->id)
        ->and($laptop->custodyLabel())->toBe('On its way');

    // Nothing moves a thing that is on its way.
    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is on its way to another place');

    $this->actingAs($this->staff)->post("/assets/movements/{$transfer->id}/receive")->assertForbidden();
    $this->actingAs($this->keeper)->post("/assets/movements/{$transfer->id}/receive")->assertRedirect();
    $laptop = $laptop->fresh();

    expect($transfer->fresh()->status)->toBe(AssetMovementStatus::Completed)
        ->and($transfer->fresh()->received_by)->toBe($this->keeper->id)
        ->and($laptop->transit_movement_id)->toBeNull()
        ->and($laptop->warehouse_id)->toBe($this->warehouse->id)
        ->and($laptop->office_id)->toBeNull();

    expect(fn () => app(MoveAssets::class)->receive($transfer, $this->keeper))->toThrow(ValidationException::class, 'has already been received');
});

it('keeps the pieces of a counted group in step with every movement', function () {
    $s = $this->scissors;
    $place = "o{$this->office->id}";

    expect(($this->holdings)($s))->toBe([$place => 4]);

    ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 3]], ['employee_id' => $this->rakib->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 3, $place => 1]);

    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 2]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'has only 1 there, not 2')
        ->and(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 0]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'Say how many')
        ->and(fn () => ($this->move)(AssetMovementKind::Return, [['item' => $s, 'quantity' => 1]], ['office_id' => $this->office->id]))
        ->toThrow(ValidationException::class, 'Say which employee is returning it')
        ->and(fn () => ($this->move)(AssetMovementKind::Return, [['item' => $s, 'quantity' => 1]], ['office_id' => $this->office->id, 'from_employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'that holder has none of it');

    ($this->move)(AssetMovementKind::Return, [['item' => $s, 'quantity' => 2]], ['office_id' => $this->office->id, 'from_employee_id' => $this->rakib->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, $place => 3]);

    // Moving every piece at the place takes the bulk row with it (on its way,
    // at neither place); the piece with Rakib stays his.
    $transfer = ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 3]], ['warehouse_id' => $this->warehouse->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1])
        ->and($s->fresh()->transit_movement_id)->toBe($transfer->id)
        ->and($s->fresh()->office_id)->toBe($this->office->id);

    app(MoveAssets::class)->receive($transfer, $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "w{$this->warehouse->id}" => 3])
        ->and($s->fresh()->warehouse_id)->toBe($this->warehouse->id)
        ->and($s->fresh()->transit_movement_id)->toBeNull();

    // A part-transfer leaves the bulk row where it is.
    $part = ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 1]], ['office_id' => $this->office->id]);
    expect($s->fresh()->transit_movement_id)->toBeNull()->and(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "w{$this->warehouse->id}" => 2]);
    app(MoveAssets::class)->receive($part, $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "o{$this->office->id}" => 1, "w{$this->warehouse->id}" => 2])
        ->and($s->fresh()->warehouse_id)->toBe($this->warehouse->id);
    // Pieces at a second place move from THERE when the form says so.
    expect(fn () => ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 1]], ['warehouse_id' => $this->warehouse->id]))
        ->toThrow(ValidationException::class, 'is already at that place');
    expect(fn () => ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 1]], ['office_id' => $this->office->id, 'from_warehouse_id' => 999]))
        ->toThrow(ValidationException::class, 'none of it is at that place');
    ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 1]], ['warehouse_id' => $this->warehouse->id, 'from_office_id' => $this->office->id]);
    app(MoveAssets::class)->receive(AssetMovement::query()->latest('id')->first(), $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "w{$this->warehouse->id}" => 3]);

    // Moving the group's home to a place that already holds pieces keeps both.
    $part2 = ($this->move)(AssetMovementKind::Transfer, [['item' => $s, 'quantity' => 1]], ['office_id' => $this->office->id]);
    app(MoveAssets::class)->receive($part2, $this->keeper);
    app(SaveAssetItem::class)->update($s->fresh(), ['name' => 'Steel scissors', 'quantity' => 4, 'office_id' => $this->office->id, 'warehouse_id' => null, 'status' => 'in_store', 'condition' => 'good'], $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "o{$this->office->id}" => 3]);
    app(SaveAssetItem::class)->update($s->fresh(), ['name' => 'Steel scissors', 'quantity' => 4, 'office_id' => null, 'warehouse_id' => $this->warehouse->id, 'status' => 'in_store', 'condition' => 'good'], $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "w{$this->warehouse->id}" => 3]);

    // Correcting the count adjusts only the place's pieces, never Rakib's.
    app(SaveAssetItem::class)->update($s->fresh(), ['name' => 'Steel scissors', 'quantity' => 6, 'office_id' => null, 'warehouse_id' => $this->warehouse->id, 'status' => 'in_store', 'condition' => 'good'], $this->keeper);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "w{$this->warehouse->id}" => 5]);

    // Counting fewer than the pieces out with people is refused.
    ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 4]], ['employee_id' => $this->nazmul->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "e{$this->nazmul->id}" => 4, "w{$this->warehouse->id}" => 1]);
    expect(fn () => app(SaveAssetItem::class)->update($s->fresh(), ['name' => 'Steel scissors', 'quantity' => 1, 'office_id' => null, 'warehouse_id' => $this->warehouse->id, 'status' => 'in_store', 'condition' => 'good'], $this->keeper))
        ->toThrow(ValidationException::class, 'Only 1 of');

    // The rebuild agrees, and catches a row somebody edited by hand.
    $this->artisan('assets:rebuild-holdings')->assertSuccessful();
    AssetHolding::query()->where('holder_key', "e{$this->rakib->id}")->update(['quantity' => 2]);
    $this->artisan('assets:rebuild-holdings')->assertFailed();
    $this->artisan('assets:rebuild-holdings', ['--write' => true])->assertSuccessful();
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "e{$this->nazmul->id}" => 4, "w{$this->warehouse->id}" => 1]);
});

it('refuses what the register would contradict', function () {
    $mouse = ($this->register)('IT', 'Mouse');
    ($this->move)(AssetMovementKind::Issue, [['item' => $mouse]], ['employee_id' => $this->rakib->id]);

    // A thing nobody holds cannot be returned.
    expect(fn () => ($this->move)(AssetMovementKind::Return, [['item' => $mouse], ['item' => $this->laptop]], ['office_id' => $this->office->id]))
        ->toThrow(ValidationException::class, 'is not with an employee');

    // Two holders on one sheet: two hand-overs, not one.
    $headset = ($this->register)('IT', 'Headset');
    ($this->move)(AssetMovementKind::Issue, [['item' => $headset]], ['employee_id' => $this->nazmul->id]);
    expect(fn () => ($this->move)(AssetMovementKind::Return, [['item' => $mouse], ['item' => $headset]], ['office_id' => $this->office->id]))
        ->toThrow(ValidationException::class, 'is not with the same holder as the others');

    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $foreignOffice = Office::query()->withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Elsewhere']);
    TenantScope::enter($this->business->id);

    expect(fn () => ($this->move)(AssetMovementKind::Transfer, [['item' => $this->laptop]], ['office_id' => $foreignOffice->id]))
        ->toThrow(ValidationException::class, 'not an active office or warehouse of this business')
        ->and(fn () => ($this->move)(AssetMovementKind::Transfer, [['item' => $this->laptop]], ['office_id' => $this->office->id, 'warehouse_id' => $this->warehouse->id]))
        ->toThrow(ValidationException::class, 'one office or one warehouse')
        ->and(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => 999]))
        ->toThrow(ValidationException::class, 'Choose an employee of this business')
        ->and(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id], '2026-09-17'))
        ->toThrow(ValidationException::class, 'in the future')
        ->and(fn () => ($this->move)(AssetMovementKind::Issue, [], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'at least one thing');

    $this->nazmul->forceFill(['status' => 'separated', 'separation_date' => '2026-09-01'])->save();
    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'Choose an employee of this business');

    $this->laptop->forceFill(['status' => AssetStatus::Missing])->save();
    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is Missing and cannot be moved');

    expect(AssetMovement::query()->count())->toBe(2);
});

it('records a hand-over through the screen, with its parts and a photo, for the keeper only', function () {
    $desktop = ($this->register)('IT', 'Desktop');
    $monitor = ($this->register)('IT', 'Monitor', ['parent_id' => $desktop->id]);

    $payload = fn (array $overrides = []) => array_replace([
        'kind' => 'issue',
        'lines' => [['item_id' => $desktop->id], ['item_id' => $monitor->id, 'condition' => 'new']],
        'employee_id' => $this->rakib->id,
        'moved_on' => '2026-09-16',
        'note' => 'For the software desk',
        'photos' => [UploadedFile::fake()->image('handover.jpg', 300, 300)],
    ], $overrides);

    $this->actingAs($this->staff)->post('/assets/movements', $payload())->assertForbidden();
    $this->actingAs($this->keeper)->post('/assets/movements', $payload())->assertRedirect('/assets/movements/1');

    $movement = AssetMovement::query()->sole();

    expect($movement->lines()->count())->toBe(2)
        ->and($movement->attachments()->where('collection', 'handover')->count())->toBe(1)
        ->and($desktop->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($monitor->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($monitor->fresh()->condition)->toBe(AssetCondition::New);

    $show = $this->actingAs($this->keeper)->get('/assets/movements/1')->assertOk()->original->getData()['page']['props'];
    expect($show['movement']['to'])->toBe('Rakib Test')->and($show['movement']['lines'])->toHaveCount(2)->and($show['can_receive'])->toBeFalse();

    // A thing of another business, or one the kind cannot take, is refused in words.
    $this->actingAs($this->keeper)->post('/assets/movements', $payload(['lines' => [['item_id' => 9999]]]))->assertSessionHasErrors('lines');
    $spare = ($this->register)('IT', 'Spare mouse');
    $this->actingAs($this->keeper)->post('/assets/movements', $payload(['lines' => [['item_id' => $spare->id], ['item_id' => 9999]], 'photos' => []]))->assertSessionHasErrors('lines');
    expect($spare->fresh()->custodian_employee_id)->toBeNull()->and(AssetMovement::query()->count())->toBe(1);
    $this->actingAs($this->keeper)->post('/assets/movements', $payload(['lines' => [['item_id' => $desktop->id]]]))->assertSessionHasErrors('lines');

    // The create screen offers only what the kind allows.
    $offered = fn (string $kind) => collect($this->actingAs($this->keeper)->get("/assets/movements/create?kind={$kind}")->original->getData()['page']['props']['items'])->pluck('tag')->all();
    expect($offered('issue'))->toBe(['BDG-IT-0001', 'BDG-IT-0004', 'BDG-SML-0001'])
        ->and($offered('return'))->toBe(['BDG-IT-0002', 'BDG-IT-0003']);

    $this->actingAs($this->staff)->get('/assets/movements')->assertForbidden();
    $this->actingAs($this->keeper)->get('/assets/movements?open=1')->assertOk();
});

it('lists what a leaver still holds, and what waits on the keeper', function () {
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], '2026-09-10');
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->scissors, 'quantity' => 2]], ['employee_id' => $this->rakib->id]);
    ($this->move)(AssetMovementKind::Transfer, [['item' => ($this->register)('IT', 'Mouse')]], ['warehouse_id' => $this->warehouse->id]);
    // Handed over today: not yet waiting on anybody.
    ($this->move)(AssetMovementKind::Issue, [['item' => ($this->register)('IT', 'Headset')]], ['employee_id' => $this->nazmul->id]);

    setPermissionsTeamId($this->business->id);
    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    $this->keeper->givePermissionTo('hrm.employees.manage');

    $checklist = $this->actingAs($this->keeper)->get("/hrm/employees/{$this->rakib->id}/checklist")->assertOk()->original->getData()['page']['props'];
    expect(collect($checklist['still_holds'])->map(fn ($t) => $t['tag'].'x'.$t['quantity'])->all())->toBe(['BDG-IT-0001x1', 'BDG-SML-0001x2'])
        ->and($checklist['can_move_assets'])->toBeTrue();

    $work = $this->actingAs($this->keeper)->get('/work')->assertOk()->original->getData()['page']['props'];
    expect($work['assets'])->toBe(['in_transit' => 1, 'unacknowledged' => 1]);

    $staffWork = $this->actingAs($this->staff)->get('/work')->assertOk()->original->getData()['page']['props'];
    expect($staffWork)->not->toHaveKey('assets');
});

it('keeps outside parties per business and switches them off, never deletes', function () {
    $this->actingAs($this->staff)->post('/assets/parties', ['name' => 'X', 'kind' => 'other'])->assertForbidden();
    $this->actingAs($this->keeper)->post('/assets/parties', ['name' => 'Rahim', 'kind' => 'other'])->assertSessionHasErrors('name');
    $this->actingAs($this->keeper)->post('/assets/parties', ['name' => 'Karim', 'organisation' => 'Karim Electronics', 'phone' => '01700000000', 'kind' => 'repair_shop'])->assertRedirect();
    $this->actingAs($this->keeper)->put("/assets/parties/{$this->shop->id}", ['name' => 'Rahim', 'kind' => 'repair_shop', 'is_active' => false])->assertRedirect();

    expect(AssetParty::query()->count())->toBe(3)->and($this->shop->fresh()->is_active)->toBeFalse();

    $list = $this->actingAs($this->keeper)->get('/assets/parties')->assertOk()->original->getData()['page']['props']['parties'];
    expect(collect($list)->pluck('name')->all())->toBe(['Karim', 'Mitu', 'Rahim']);
});
