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
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Notifications\AssetHandedOverNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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
    $this->actingAs($this->keeper)->post('/assets/movements', $payload())->assertRedirect('/assets/movements/'.AssetMovement::query()->sole()->id);

    $movement = AssetMovement::query()->sole();

    expect($movement->lines()->count())->toBe(2)
        ->and($movement->attachments()->where('collection', 'handover')->count())->toBe(1)
        ->and($desktop->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($monitor->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($monitor->fresh()->condition)->toBe(AssetCondition::New);

    $show = $this->actingAs($this->keeper)->get("/assets/movements/{$movement->id}")->assertOk()->original->getData()['page']['props'];
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
    expect($work['assets'])->toBe(['in_transit' => 1, 'unacknowledged' => 1, 'open_gaps' => 0, 'maintenance_due' => 0, 'open_repairs' => 0]);

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

it('passes a thing from one employee to another in one document', function () {
    // Nobody holds it yet: nothing to pass on.
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'is not with an employee');

    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], '2026-09-10');
    $this->laptop->fresh()->forceFill(['acknowledged_at' => Carbon::now()])->save();

    // To the same person: refused.
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is already with that person');

    $reassign = ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop, 'condition' => 'fair']], ['employee_id' => $this->nazmul->id]);
    $laptop = $this->laptop->fresh();

    expect($reassign->kind)->toBe(AssetMovementKind::Reassign)
        ->and($reassign->status)->toBe(AssetMovementStatus::Completed)
        ->and($reassign->fromLabel())->toBe('Rakib Test')
        ->and($reassign->toLabel())->toBe('Nazmul Test')
        ->and($reassign->from_employee_id)->toBe($this->rakib->id)
        ->and($laptop->custodian_employee_id)->toBe($this->nazmul->id)
        ->and($laptop->status)->toBe(AssetStatus::InUse)
        ->and($laptop->condition)->toBe(AssetCondition::Fair)
        ->and($laptop->custody_since->toDateString())->toBe('2026-09-16')
        // ⚠ The new holder has to acknowledge it again.
        ->and($laptop->acknowledged_at)->toBeNull()
        ->and($laptop->office_id)->toBe($this->office->id);

    // Rakib no longer sees it in My assets.
    expect($this->actingAs($this->staff)->get('/hrm/my-assets')->original->getData()['page']['props']['items'])->toHaveCount(0);

    // The pieces of a counted group move between the two employees' holdings.
    $s = $this->scissors;
    ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 3]], ['employee_id' => $this->rakib->id]);
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $s, 'quantity' => 2]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'Say which employee is returning it')
        ->and(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $s, 'quantity' => 2]], ['employee_id' => $this->rakib->id, 'from_employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is already with that person')
        ->and(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $s, 'quantity' => 4]], ['employee_id' => $this->nazmul->id, 'from_employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'has only 3 there, not 4');

    ($this->move)(AssetMovementKind::Reassign, [['item' => $s, 'quantity' => 2]], ['employee_id' => $this->nazmul->id, 'from_employee_id' => $this->rakib->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "e{$this->nazmul->id}" => 2, "o{$this->office->id}" => 1]);

    // The rebuild reproduces the holdings from the movements after a reassign.
    $this->artisan('assets:rebuild-holdings')->assertSuccessful();
    AssetHolding::query()->where('asset_item_id', $s->id)->delete();
    $this->artisan('assets:rebuild-holdings', ['--write' => true])->assertSuccessful();
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "e{$this->nazmul->id}" => 2, "o{$this->office->id}" => 1]);

    // The screen: the list for a reassign is what employees hold, each row saying who.
    $create = $this->actingAs($this->keeper)->get('/assets/movements/create?kind=reassign')->assertOk()->original->getData()['page']['props'];
    expect(collect($create['items'])->map(fn ($i) => $i['tag'].':'.($i['custodian_employee_id'] ?? '-'))->all())->toBe(['BDG-IT-0001:'.$this->nazmul->id, 'BDG-SML-0001:-'])
        ->and(collect($create['kinds'])->pluck('label')->all())->toContain('From one person to another');

    $this->actingAs($this->keeper)->post('/assets/movements', [
        'kind' => 'reassign', 'lines' => [['item_id' => $laptop->id]], 'employee_id' => $this->rakib->id, 'moved_on' => '2026-09-16',
    ])->assertRedirect('/assets/movements/'.AssetMovement::query()->max('id'));
    expect($laptop->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and(AssetMovement::query()->latest('id')->first()->fromLabel())->toBe('Nazmul Test');
});

it('sends a thing to an outside party straight from the employee holding it', function () {
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], '2026-09-10');
    $mouse = ($this->register)('IT', 'Mouse');

    // The wrong employee named as the source: refused; two holders on one sheet: refused.
    expect(fn () => ($this->move)(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->freelancer->id, 'from_employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'is with Rakib Test, not with the employee chosen')
        ->and(fn () => ($this->move)(AssetMovementKind::SendOut, [['item' => $this->laptop], ['item' => $mouse]], ['party_id' => $this->freelancer->id]))
        ->toThrow(ValidationException::class, 'is not with the same holder as the others');

    $sendOut = ($this->move)(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->freelancer->id]);
    $laptop = $this->laptop->fresh();

    expect($sendOut->from_employee_id)->toBe($this->rakib->id)
        ->and($sendOut->fromLabel())->toBe('Rakib Test')
        ->and($sendOut->toLabel())->toBe('Mitu')
        ->and($laptop->custodian_employee_id)->toBeNull()
        ->and($laptop->custodian_party_id)->toBe($this->freelancer->id)
        ->and($laptop->status)->toBe(AssetStatus::WithOutsideParty)
        ->and($laptop->custody_since->toDateString())->toBe('2026-09-16');

    // A party never passes a thing on to another party on our books.
    expect(fn () => ($this->move)(AssetMovementKind::SendOut, [['item' => $laptop]], ['party_id' => $this->shop->id]))
        ->toThrow(ValidationException::class, 'is with Mitu. Receive it back first');

    // A counted group's pieces leave the employee named, or the place when none is.
    $s = $this->scissors;
    ($this->move)(AssetMovementKind::Issue, [['item' => $s, 'quantity' => 3]], ['employee_id' => $this->rakib->id]);
    ($this->move)(AssetMovementKind::SendOut, [['item' => $s, 'quantity' => 2]], ['party_id' => $this->shop->id, 'from_employee_id' => $this->rakib->id]);
    ($this->move)(AssetMovementKind::SendOut, [['item' => $s, 'quantity' => 1]], ['party_id' => $this->shop->id]);
    expect(($this->holdings)($s))->toBe(["e{$this->rakib->id}" => 1, "p{$this->shop->id}" => 3]);
    $this->artisan('assets:rebuild-holdings')->assertSuccessful();

    // The screen offers held things for a send-out too; a thing with a party is left out.
    $offered = collect($this->actingAs($this->keeper)->get('/assets/movements/create?kind=send_out')->original->getData()['page']['props']['items']);
    expect($offered->pluck('tag')->all())->toBe(['BDG-IT-0002', 'BDG-SML-0001']);
});

it('hands a thing only to an active employee of this business, and tells them', function () {
    Notification::fake();
    $suspended = ($this->employee)('Sumon');
    $suspended->forceFill(['status' => 'suspended'])->save();
    $draft = ($this->employee)('Dipu');
    $draft->forceFill(['status' => 'draft'])->save();

    // ⚠ Not a draft, not someone suspended, not a leaver: none can sign for it.
    expect(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $suspended->id]))
        ->toThrow(ValidationException::class, 'Choose an employee of this business')
        ->and(fn () => ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $draft->id]))
        ->toThrow(ValidationException::class, 'Choose an employee of this business');

    // The screen offers the same people and nobody else.
    $create = $this->actingAs($this->keeper)->get('/assets/movements/create?kind=issue')->assertOk()->original->getData()['page']['props'];
    expect(collect($create['employees'])->pluck('id')->all())->toBe([$this->nazmul->id, $this->rakib->id]);

    $issue = ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id]);

    // Rakib's login hears about it, with the words that send him to My assets.
    Notification::assertSentTo($this->staff, AssetHandedOverNotification::class, function (AssetHandedOverNotification $bell) {
        $data = $bell->toDatabase($this->staff);

        return $data['summary'] === 'You now hold BDG-IT-0001 ThinkPad T470s; please acknowledge in My assets (AM-000001).'
            && $data['url'] === '/hrm/my-assets'
            && $data['kind'] === 'asset_handed_over';
    });

    // Passing it to a leaver: refused; nothing moved.
    $this->nazmul->forceFill(['status' => 'separated', 'separation_date' => '2026-09-01'])->save();
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id]))
        ->toThrow(ValidationException::class, 'Choose an employee of this business')
        ->and($this->laptop->fresh()->custodian_employee_id)->toBe($this->rakib->id);

    $this->nazmul->forceFill(['status' => 'active', 'separation_date' => null])->save();
    $reassign = ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id]);

    // ⚠ Three different facts on the document: who gave, who received, who
    // recorded it. The keeper is not an employee and is on neither side.
    expect($reassign->from_employee_id)->toBe($this->rakib->id)
        ->and($reassign->to_employee_id)->toBe($this->nazmul->id)
        ->and($reassign->created_by)->toBe($this->keeper->id)
        ->and($this->keeper->id)->not->toBe($this->staff->id)
        ->and(Employee::query()->withoutGlobalScopes()->where('user_id', $this->keeper->id)->exists())->toBeFalse()
        ->and($issue->created_by)->toBe($this->keeper->id);

    // Nazmul has no login: no bell, no error. Only the one to Rakib was sent.
    Notification::assertCount(1);
});

it('refuses a hand-over from a stale screen, and lets a returned thing be handed on again', function () {
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], '2026-09-10');

    // Returned meanwhile: there is nobody to take it from.
    ($this->move)(AssetMovementKind::Return, [['item' => $this->laptop]], ['office_id' => $this->office->id]);
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id, 'from_employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is not with an employee');

    // Handed to Nazmul since; the keeper's screen still says Rakib: refused,
    // never quietly taken from Nazmul.
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id]);
    expect(fn () => ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id, 'from_employee_id' => $this->rakib->id]))
        ->toThrow(ValidationException::class, 'is with Nazmul Test, not with the employee chosen')
        ->and($this->laptop->fresh()->custodian_employee_id)->toBe($this->nazmul->id);

    $this->actingAs($this->keeper)->post('/assets/movements', [
        'kind' => 'reassign', 'lines' => [['item_id' => $this->laptop->id]], 'employee_id' => $this->rakib->id, 'from_employee_id' => $this->rakib->id, 'moved_on' => '2026-09-16',
    ])->assertSessionHasErrors('lines');

    // Returned, handed out again, then passed on: the chain is unbroken.
    ($this->move)(AssetMovementKind::Return, [['item' => $this->laptop]], ['office_id' => $this->office->id, 'from_employee_id' => $this->nazmul->id]);
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id]);
    $reassign = ($this->move)(AssetMovementKind::Reassign, [['item' => $this->laptop]], ['employee_id' => $this->nazmul->id, 'from_employee_id' => $this->rakib->id]);

    expect($reassign->fromLabel())->toBe('Rakib Test')
        ->and($reassign->toLabel())->toBe('Nazmul Test')
        ->and($this->laptop->fresh()->custodian_employee_id)->toBe($this->nazmul->id)
        ->and($this->laptop->fresh()->acknowledged_at)->toBeNull()
        ->and(AssetMovement::query()->pluck('kind')->map(fn ($kind) => $kind->value)->all())->toBe(['issue', 'return', 'issue', 'return', 'issue', 'reassign']);
});

it('records one hand-over for two identical reassign posts', function () {
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], '2026-09-10');

    $payload = [
        'kind' => 'reassign', 'lines' => [['item_id' => $this->laptop->id]], 'employee_id' => $this->nazmul->id,
        'from_employee_id' => $this->rakib->id, 'moved_on' => '2026-09-16',
    ];

    $this->actingAs($this->keeper)->post('/assets/movements', $payload)->assertRedirect('/assets/movements/'.AssetMovement::query()->max('id'));

    // ⚠ The same post again, the way a double-click or a retry sends it. The
    // in-flight lock (PreventDuplicateSubmission) has let go by now, so it is
    // the register that refuses: Nazmul already holds it, Rakib no longer does.
    $this->actingAs($this->keeper)->post('/assets/movements', $payload)->assertSessionHasErrors('lines');

    expect(AssetMovement::query()->where('kind', 'reassign')->count())->toBe(1)
        ->and(AssetMovement::query()->count())->toBe(2)
        ->and($this->laptop->fresh()->custodian_employee_id)->toBe($this->nazmul->id);
});

it('says when a thing sent out is expected back, and flags it overdue until it comes back', function () {
    expect(fn () => app(MoveAssets::class)->handle(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->shop->id], $this->keeper, '2026-09-16', null, '2026-09-10'))
        ->toThrow(ValidationException::class, 'cannot be before the day it was sent out');

    $sendOut = app(MoveAssets::class)->handle(AssetMovementKind::SendOut, [['item' => $this->laptop]], ['party_id' => $this->shop->id], $this->keeper, '2026-09-16', 'Screen flickers', '2026-09-20');
    expect($sendOut->expected_back_on->toDateString())->toBe('2026-09-20')->and($sendOut->isOverdue())->toBeFalse();

    $page = fn () => $this->actingAs($this->keeper)->get("/assets/items/{$this->laptop->id}")->assertOk()->original->getData()['page']['props'];
    $list = fn (string $query = '') => $this->actingAs($this->keeper)->get("/assets/items{$query}")->assertOk()->original->getData()['page']['props'];

    expect($page()['item']['outside'])->toBe([
        'party' => $this->shop->label(), 'since' => '2026-09-16', 'expected_back_on' => '2026-09-20', 'overdue' => false,
        'movement' => ['id' => $sendOut->id, 'number' => 'AM-000001'],
    ])->and($list()['counts']['overdue_outside'])->toBe(0)
        ->and(collect($list()['items']['data'])->firstWhere('tag', 'BDG-IT-0001')['overdue'])->toBeFalse();

    // The day after: overdue on the thing, on the list and in the filter.
    Carbon::setTestNow('2026-09-21 10:00:00');
    expect($page()['item']['outside']['overdue'])->toBeTrue()
        ->and($list()['counts']['overdue_outside'])->toBe(1)
        ->and(collect($list()['items']['data'])->firstWhere('tag', 'BDG-IT-0001')['overdue'])->toBeTrue()
        ->and(collect($list('?overdue=1')['items']['data'])->pluck('tag')->all())->toBe(['BDG-IT-0001'])
        ->and($this->actingAs($this->keeper)->get("/assets/movements/{$sendOut->id}")->original->getData()['page']['props']['movement']['overdue'])->toBeTrue();

    // Received back: the date stays on the send-out, the thing is no longer overdue.
    app(MoveAssets::class)->handle(AssetMovementKind::ReceiveBack, [['item' => $this->laptop]], ['office_id' => $this->office->id], $this->keeper);
    expect($page()['item']['outside'])->toBeNull()
        ->and($list()['counts']['overdue_outside'])->toBe(0)
        ->and($list('?overdue=1')['items']['data'])->toBe([])
        ->and($sendOut->fresh()->expected_back_on->toDateString())->toBe('2026-09-20');

    // Through the screen, with the date; and a date before the day refused.
    $this->actingAs($this->keeper)->post('/assets/movements', [
        'kind' => 'send_out', 'lines' => [['item_id' => $this->laptop->id]], 'party_id' => $this->shop->id, 'moved_on' => '2026-09-21', 'expected_back_on' => '2026-09-20',
    ])->assertSessionHasErrors('expected_back_on');
    $this->actingAs($this->keeper)->post('/assets/movements', [
        'kind' => 'send_out', 'lines' => [['item_id' => $this->laptop->id]], 'party_id' => $this->shop->id, 'moved_on' => '2026-09-21', 'expected_back_on' => '2026-09-25',
    ])->assertRedirect('/assets/movements/'.AssetMovement::query()->max('id'));
    expect(AssetMovement::query()->latest('id')->first()->expected_back_on->toDateString())->toBe('2026-09-25')
        ->and($page()['item']['outside']['expected_back_on'])->toBe('2026-09-25');

    // An issue never carries one, even when handed the date.
    app(MoveAssets::class)->handle(AssetMovementKind::ReceiveBack, [['item' => $this->laptop]], ['office_id' => $this->office->id], $this->keeper);
    $issue = app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper, null, null, '2026-09-30');
    expect($issue->expected_back_on)->toBeNull();
});

// Add and edit an outside party are one popup over the list since 14 Oct 2026
// (Add was a form above the list and Edit replaced the row). The page carries
// what the popup draws, including the note it carries back unchanged, and the
// popup saves through the same routes.
it('carries what the outside party popup needs on the list, and saves through the same routes', function () {
    $this->shop->update(['note' => 'Opens at 11']);

    $this->actingAs($this->keeper)->post('/assets/parties', [
        'name' => 'Karim', 'organisation' => '', 'phone' => '', 'kind' => 'freelancer', 'supplier_id' => '', 'note' => '', 'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    // The edit popup sends every field back, the note included, so nothing is cleared.
    $this->actingAs($this->keeper)->put("/assets/parties/{$this->shop->id}", [
        'name' => 'Rahim', 'organisation' => 'Rahim Computer Care', 'phone' => '01711000000', 'kind' => 'repair_shop',
        'supplier_id' => '', 'note' => 'Opens at 11', 'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->shop->fresh())->phone->toBe('01711000000')->note->toBe('Opens at 11');

    $this->actingAs($this->keeper)->get('/assets/parties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assets/parties/index')
            ->where('can_manage', true)
            ->has('kinds')
            ->has('suppliers')
            ->where('parties', fn ($parties) => collect($parties)->contains(fn ($row) => $row['id'] === $this->shop->id
                && $row['kind'] === 'repair_shop'
                && $row['supplier_id'] === null
                && $row['note'] === 'Opens at 11'
                && $row['is_active'] === true)));

    // Someone who may only look gets the list, not the popup.
    Role::create(['name' => 'Party reader', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('assets.items.view');
    $this->actingAs(($this->user)('Party reader'))->get('/assets/parties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_manage', false));
});

// ⚠ Bug hunt 16 Oct 2026 (03 M4): My assets lists what a person holds in every
// business, but its buttons resolved the thing in the business selected now,
// so they were a 404 for a thing held in a sister business.
it('acknowledges and reports a thing held in a sister business while another is selected', function () {
    ($this->move)(AssetMovementKind::Issue, [['item' => $this->laptop, 'condition' => 'good']], ['employee_id' => $this->rakib->id]);
    $sister = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia-m4']);
    $this->staff->businesses()->attach($sister->id);

    $this->actingAs($this->staff)->withSession(['current_business_id' => $sister->id])
        ->post("/hrm/my-assets/{$this->laptop->id}/acknowledge")->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->withSession(['current_business_id' => $sister->id])
        ->post("/hrm/my-assets/{$this->laptop->id}/report", ['problem' => 'The screen flickers'])->assertRedirect()->assertSessionHasNoErrors();

    $record = AssetMaintenanceRecord::query()->withoutGlobalScopes()->sole();
    expect($this->laptop->fresh()->acknowledged_at)->not->toBeNull()
        ->and($record->business_id)->toBe($this->business->id);
});
