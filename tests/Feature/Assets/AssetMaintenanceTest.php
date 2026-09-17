<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\Actions\SaveMaintenancePlan;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Notifications\AssetMaintenanceDueNotification;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset management slice A4: what should be done to a thing and when, and
 * what was done. Plan §4e.
 *
 * ⭐ Doing a plan's work moves the plan on from the day it was done; "out of
 * service" is put back only if nothing else changed the status; the money is
 * seen and set only by whoever sees the financial register.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-17 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
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
    $this->stranger = User::factory()->create();
    $this->stranger->businesses()->attach($this->other->id);

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);

    $this->rakib = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Rakib', 'last_name' => 'Test', 'employment_type' => 'permanent',
        'status' => 'active', 'joining_date' => '2026-01-01', 'user_id' => $this->staff->id,
    ]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->rakib->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);
    $this->shop = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'organisation' => 'IDB Bhaban', 'kind' => 'repair_shop']);

    $this->register = fn (string $code, string $name, array $overrides = []) => app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', $code)->sole(),
        array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
        $this->keeper,
    )->first();

    $this->laptop = ($this->register)('IT', 'ThinkPad');
    $this->printer = ($this->register)('PRN', 'LaserJet');
    $this->drone = ($this->register)('CAM', 'DJI Air 3');
    $this->scissors = ($this->register)('SML', 'Scissors', ['tracking' => 'quantity', 'quantity' => 2]);

    $this->plans = app(SaveMaintenancePlan::class);
    $this->records = app(RecordMaintenance::class);
    $this->monthly = fn (AssetItem $item, array $overrides = []) => $this->plans->save($item, array_replace([
        'title' => 'Clean the head', 'every_count' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-09-20', 'remind_days' => 3,
    ], $overrides), $this->keeper);

    $ledger = Account::create(['business_id' => $this->business->id, 'code' => '1010', 'name' => 'Cash', 'type' => AccountType::Asset, 'is_postable' => true, 'is_active' => true]);
    $money = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Petty cash', 'type' => 'cash', 'account_id' => $ledger->id, 'is_active' => true]);
    $this->voucher = ExpenseVoucher::create([
        'business_id' => $this->business->id, 'voucher_number' => 'EV-0001', 'spent_on' => '2026-09-16',
        'money_account_id' => $money->id, 'paid_to' => 'Rahim', 'total' => '1500.0000', 'created_by' => $this->manager->id,
    ]);
    $this->foreignVoucher = ExpenseVoucher::withoutGlobalScopes()->create([
        'business_id' => $this->other->id, 'voucher_number' => 'EV-0001', 'spent_on' => '2026-09-16',
        'money_account_id' => $money->id, 'paid_to' => 'Elsewhere', 'total' => '99.0000', 'created_by' => $this->manager->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('plans only for a tagged thing still kept, done by someone of this business', function () {
    $plan = ($this->monthly)($this->printer, ['assignee_user_id' => $this->staff->id]);

    expect($plan->remind_from->toDateString())->toBe('2026-09-17')
        ->and($plan->everyLabel())->toBe('Every month')
        ->and($plan->whoLabel())->toBe($this->staff->name)
        ->and(Activity::query()->where('subject_id', $this->printer->id)->where('description', 'like', 'Maintenance planned: Clean the head%')->exists())->toBeTrue();

    $refused = [
        [$this->scissors, [], 'not a counted group'],
        [$this->printer, ['title' => '  '], 'Say what should be done'],
        [$this->printer, ['every_count' => 0], 'Repeat every 1 to 366'],
        [$this->printer, ['every_count' => 367], 'Repeat every 1 to 366'],
        [$this->printer, ['every_unit' => 'year'], 'days, weeks or months'],
        [$this->printer, ['next_due_on' => ''], 'Say when it is next due'],
        [$this->printer, ['next_due_on' => '2037-01-01'], 'within the next ten years'],
        [$this->printer, ['remind_days' => 91], 'between 0 and 90'],
        [$this->printer, ['remind_days' => -1], 'between 0 and 90'],
        [$this->printer, ['assignee_user_id' => $this->stranger->id], 'does not work in this business'],
        [$this->printer, ['assignee_user_id' => $this->staff->id, 'party_id' => $this->shop->id], 'not both'],
    ];

    foreach ($refused as [$item, $overrides, $message]) {
        expect(fn () => ($this->monthly)($item, $overrides))->toThrow(ValidationException::class, $message);
    }

    $foreignShop = AssetParty::withoutGlobalScopes()->create(['business_id' => $this->other->id, 'name' => 'Far away', 'kind' => 'repair_shop']);
    $this->shop->forceFill(['is_active' => false])->save();
    expect(fn () => ($this->monthly)($this->printer, ['party_id' => $foreignShop->id]))->toThrow(ValidationException::class, 'not an active one of this business')
        ->and(fn () => ($this->monthly)($this->printer, ['party_id' => $this->shop->id]))->toThrow(ValidationException::class, 'not an active one of this business');

    // A plan belongs to its own thing.
    expect(fn () => $this->plans->save($this->laptop, ['title' => 'X', 'every_count' => 1, 'every_unit' => 'day', 'next_due_on' => '2026-09-20'], $this->keeper, $plan))
        ->toThrow(ValidationException::class, 'belongs to another thing');

    // Editing recomputes the reminder date.
    $edited = $this->plans->save($this->printer, ['title' => 'Clean the head', 'every_count' => 2, 'every_unit' => 'week', 'next_due_on' => '2026-10-01', 'remind_days' => 5], $this->keeper, $plan);
    expect($edited->remind_from->toDateString())->toBe('2026-09-26')->and($edited->everyLabel())->toBe('Every 2 weeks');

    // Pausing is recorded; a thing that is missing or disposed keeps its plan paused.
    $this->plans->setActive($plan, false, $this->keeper);
    $this->printer->forceFill(['status' => AssetStatus::Missing])->save();
    expect(fn () => $this->plans->setActive($plan->fresh(), true, $this->keeper))->toThrow(ValidationException::class, 'stays paused')
        ->and(fn () => ($this->monthly)($this->printer->fresh()))->toThrow(ValidationException::class, 'nothing can be planned')
        ->and(Activity::query()->where('description', 'Maintenance plan paused: Clean the head')->exists())->toBeTrue();

    $this->printer->forceFill(['status' => AssetStatus::InStore])->save();
    expect($this->plans->setActive($plan->fresh(), true, $this->keeper)->is_active)->toBeTrue();
});

it('moves a plan on from the day its work was done, and never back', function () {
    $plan = ($this->monthly)($this->printer);

    $record = $this->records->open($this->printer, [
        'kind' => 'preventive', 'problem' => 'Monthly clean', 'plan_id' => $plan->id, 'reported_on' => '2026-09-15',
        'done' => true, 'done_on' => '2026-09-15', 'work_done' => 'Cleaned the head and rollers',
    ], $this->keeper);

    $plan->refresh();
    expect($record->state)->toBe(MaintenanceState::Done)
        ->and($record->number)->toBe('MR-000001')
        ->and($plan->last_done_on->toDateString())->toBe('2026-09-15')
        ->and($plan->next_due_on->toDateString())->toBe('2026-10-15')
        ->and($plan->remind_from->toDateString())->toBe('2026-10-12');

    // Older work recorded late does not pull the plan back.
    $this->records->open($this->printer, [
        'kind' => 'preventive', 'problem' => 'August clean', 'plan_id' => $plan->id, 'reported_on' => '2026-08-15',
        'done' => true, 'done_on' => '2026-08-15', 'work_done' => 'Cleaned',
    ], $this->keeper);
    expect($plan->fresh()->next_due_on->toDateString())->toBe('2026-10-15');

    // A month from the 31st is the end of the next month, not a day of the one after.
    $drone = ($this->monthly)($this->drone, ['title' => 'Service']);
    $this->records->open($this->drone, ['kind' => 'preventive', 'problem' => 'Service', 'plan_id' => $drone->id, 'reported_on' => '2026-01-31', 'done' => true, 'done_on' => '2026-01-31', 'work_done' => 'Done'], $this->keeper);
    expect($drone->fresh()->next_due_on->toDateString())->toBe('2026-02-28');

    // Another thing's plan is refused, and nothing is written.
    expect(fn () => $this->records->open($this->laptop, ['kind' => 'preventive', 'problem' => 'X', 'plan_id' => $plan->id], $this->keeper))
        ->toThrow(ValidationException::class, "not one of {$this->laptop->tag}'s")
        ->and(AssetMaintenanceRecord::query()->where('asset_item_id', $this->laptop->id)->exists())->toBeFalse();

    // Due: inside the reminder window or overdue, active, on a thing still kept.
    $due = fn () => AssetMaintenancePlan::query()->due()->pluck('id')->all();
    $laptopPlan = ($this->monthly)($this->laptop, ['next_due_on' => '2026-09-10']);
    $later = ($this->monthly)($this->laptop, ['title' => 'Later', 'next_due_on' => '2026-09-21']);
    // (The drone's plan fell due in February.)
    expect($due())->toBe([$drone->id, $laptopPlan->id])
        ->and($laptopPlan->isOverdue())->toBeTrue()
        ->and($later->isOverdue())->toBeFalse();

    $this->plans->setActive($laptopPlan, false, $this->keeper);
    expect($due())->toBe([$drone->id]);
    $this->plans->setActive($laptopPlan, true, $this->keeper);
    $this->laptop->forceFill(['status' => AssetStatus::Disposed])->save();
    expect($due())->toBe([$drone->id]);
});

it('takes a thing out of service and puts its status back only if nothing else changed it', function () {
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper);
    $this->laptop->refresh();

    $record = $this->records->open($this->laptop, ['kind' => 'repair', 'problem' => 'Screen flickers', 'out_of_service' => true], $this->keeper);
    expect($this->laptop->fresh()->status)->toBe(AssetStatus::UnderRepair)
        ->and($record->status_before)->toBe('in_use')
        ->and($record->reported_on->toDateString())->toBe('2026-09-17');

    $this->records->complete($record, ['done_on' => '2026-09-17', 'work_done' => 'Cable reseated', 'condition_after' => 'fair', 'party_id' => $this->shop->id], $this->keeper);
    $laptop = $this->laptop->fresh();
    expect($laptop->status)->toBe(AssetStatus::InUse)
        ->and($laptop->condition)->toBe(AssetCondition::Fair)
        ->and($record->fresh()->whoLabel())->toBe('Rahim (IDB Bhaban)');

    // Cancelled rather than done: the status goes back all the same.
    $record = $this->records->open($this->printer, ['kind' => 'repair', 'problem' => 'Noise', 'out_of_service' => true], $this->keeper);
    expect($this->printer->fresh()->status)->toBe(AssetStatus::UnderRepair);
    $this->records->cancel($record, 'Not a fault', $this->keeper);
    expect($this->printer->fresh()->status)->toBe(AssetStatus::InStore);

    // Sent to the repair shop meanwhile: closing leaves it with the shop.
    $record = $this->records->open($this->printer, ['kind' => 'repair', 'problem' => 'Paper jam', 'out_of_service' => true], $this->keeper);
    app(MoveAssets::class)->handle(AssetMovementKind::SendOut, [['item' => $this->printer->fresh()]], ['party_id' => $this->shop->id], $this->keeper);
    $this->records->cancel($record, 'The shop has it now', $this->keeper);
    expect($this->printer->fresh()->status)->toBe(AssetStatus::UnderRepair);

    // Changed by hand meanwhile: closing leaves the hand edit alone.
    $record = $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'Gimbal', 'out_of_service' => true], $this->keeper);
    $this->drone->forceFill(['status' => AssetStatus::InUse])->save();
    $this->records->complete($record, ['done_on' => '2026-09-17', 'work_done' => 'Fixed'], $this->keeper);
    expect($this->drone->fresh()->status)->toBe(AssetStatus::InUse);

    // Already under repair: nothing to put back.
    $this->drone->forceFill(['status' => AssetStatus::UnderRepair])->save();
    $record = $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'Still broken', 'out_of_service' => true], $this->keeper);
    expect($record->status_before)->toBeNull();
    $this->records->cancel($record, 'Duplicate', $this->keeper);
    expect($this->drone->fresh()->status)->toBe(AssetStatus::UnderRepair);

    // On its way to another place meanwhile (a transfer sets it In store, and
    // somebody marked it Under repair again on the way): closing leaves it alone.
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);
    $camera = ($this->register)('CAM', 'Canon R50', ['status' => 'in_use']);
    $record = $this->records->open($camera, ['kind' => 'repair', 'problem' => 'Lens', 'out_of_service' => true], $this->keeper);
    app(MoveAssets::class)->handle(AssetMovementKind::Transfer, [['item' => $camera->fresh()]], ['warehouse_id' => $warehouse->id], $this->keeper);
    $camera->refresh()->forceFill(['status' => AssetStatus::UnderRepair])->save();
    expect($camera->isInTransit())->toBeTrue();
    $this->records->cancel($record, 'Sent to the warehouse for the shop', $this->keeper);
    expect($camera->fresh()->status)->toBe(AssetStatus::UnderRepair);

    // Not out of service: the status is not touched.
    $this->records->open($this->laptop, ['kind' => 'inspection', 'problem' => 'Yearly check'], $this->keeper);
    expect($this->laptop->fresh()->status)->toBe(AssetStatus::InUse);
});

it('refuses what cannot be true, and closes a record once', function () {
    $refused = [
        [$this->scissors, ['kind' => 'repair', 'problem' => 'Blunt'], 'not a counted group'],
        [$this->printer, ['kind' => 'overhaul', 'problem' => 'X'], 'what kind of work'],
        [$this->printer, ['kind' => 'repair', 'problem' => ' '], 'what is wrong'],
        [$this->printer, ['kind' => 'repair', 'problem' => 'X', 'reported_on' => '2026-09-18'], 'in the future'],
        [$this->printer, ['kind' => 'repair', 'problem' => 'X', 'reported_on' => '1999-12-31'], 'too far back'],
    ];

    foreach ($refused as [$item, $a, $message]) {
        expect(fn () => $this->records->open($item, $a, $this->keeper))->toThrow(ValidationException::class, $message);
    }

    $this->drone->forceFill(['status' => AssetStatus::Missing])->save();
    expect(fn () => $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'X'], $this->keeper))->toThrow(ValidationException::class, 'written down as missing');
    $this->drone->forceFill(['status' => AssetStatus::Disposed])->save();
    expect(fn () => $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'X'], $this->keeper))->toThrow(ValidationException::class, 'was disposed')
        ->and(AssetMaintenanceRecord::query()->count())->toBe(0);

    $record = $this->records->open($this->printer, ['kind' => 'repair', 'problem' => 'Jam', 'reported_on' => '2026-09-10'], $this->keeper);

    expect(fn () => $this->records->complete($record, ['work_done' => 'Fixed'], $this->keeper))->toThrow(ValidationException::class, 'when the work was done')
        ->and(fn () => $this->records->complete($record, ['done_on' => '2026-09-09', 'work_done' => 'Fixed'], $this->keeper))->toThrow(ValidationException::class, 'before it was reported (2026-09-10)')
        ->and(fn () => $this->records->complete($record, ['done_on' => '2026-09-18', 'work_done' => 'Fixed'], $this->keeper))->toThrow(ValidationException::class, 'in the future')
        ->and(fn () => $this->records->complete($record, ['done_on' => '2026-09-10', 'work_done' => ''], $this->keeper))->toThrow(ValidationException::class, 'Say what was done')
        ->and(fn () => $this->records->complete($record, ['done_on' => '2026-09-10', 'work_done' => 'X', 'condition_after' => 'shiny'], $this->keeper))->toThrow(ValidationException::class, 'Choose a condition')
        ->and(fn () => $this->records->complete($record, ['done_on' => '2026-09-10', 'work_done' => 'X', 'done_by_user_id' => $this->stranger->id], $this->keeper))->toThrow(ValidationException::class, 'does not work in this business')
        ->and($record->fresh()->isOpen())->toBeTrue();

    $this->records->complete($record, ['done_on' => '2026-09-10', 'work_done' => 'Fixed', 'done_by_user_id' => $this->staff->id], $this->keeper);

    expect(fn () => $this->records->complete($record, ['done_on' => '2026-09-11', 'work_done' => 'Again'], $this->keeper))->toThrow(ValidationException::class, 'MR-000001 is already Done')
        ->and(fn () => $this->records->cancel($record, 'Oops', $this->keeper))->toThrow(ValidationException::class, 'already Done')
        ->and($record->fresh()->work_done)->toBe('Fixed');

    $second = $this->records->open($this->printer, ['kind' => 'repair', 'problem' => 'Jam again'], $this->keeper);
    expect($second->number)->toBe('MR-000002')
        ->and(fn () => $this->records->cancel($second, '  ', $this->keeper))->toThrow(ValidationException::class, 'why it is cancelled');

    $this->records->cancel($second, 'Not a fault: wrong paper', $this->keeper);
    expect($second->fresh()->state)->toBe(MaintenanceState::Cancelled)
        ->and(fn () => $this->records->complete($second, ['done_on' => '2026-09-17', 'work_done' => 'X'], $this->keeper))->toThrow(ValidationException::class, 'already Cancelled');
});

it('lets only the financial register set what the work cost, and only with a voucher of this business', function () {
    $record = $this->records->open($this->printer, ['kind' => 'repair', 'problem' => 'Jam'], $this->keeper);

    // The keeper's cost is not taken, even on the way through.
    $this->records->complete($record, ['done_on' => '2026-09-17', 'work_done' => 'Fixed', 'cost' => '900', 'expense_voucher_id' => $this->voucher->id], $this->keeper);
    expect($record->fresh()->cost)->toBeNull()->and($record->fresh()->expense_voucher_id)->toBeNull()
        ->and(fn () => $this->records->setMoney($record, ['cost' => '900'], $this->keeper))->toThrow(ValidationException::class, 'Only someone who sees the financial register');

    expect(fn () => $this->records->setMoney($record, ['cost' => '900', 'expense_voucher_id' => $this->foreignVoucher->id], $this->manager))->toThrow(ValidationException::class, 'not one of this business')
        ->and(fn () => $this->records->setMoney($record, ['cost' => '-1'], $this->manager))->toThrow(ValidationException::class, 'cannot be negative');

    // Done records take their money later: the bill often comes after the work.
    $this->records->setMoney($record, ['cost' => '900.50', 'expense_voucher_id' => $this->voucher->id], $this->manager);
    expect($record->fresh()->cost)->toBe('900.5000')
        ->and($record->fresh()->expense_voucher_id)->toBe($this->voucher->id);

    // ⚠ The history says that money was recorded, never how much.
    $logged = Activity::query()->where('description', 'like', 'MR-000001: cost recorded%')->sole();
    expect($logged->description)->toBe('MR-000001: cost recorded, paid by EV-0001')->not->toContain('900');

    // A Manager completing sets it in the same step.
    $second = $this->records->open($this->laptop, ['kind' => 'repair', 'problem' => 'Fan'], $this->keeper);
    $this->records->complete($second, ['done_on' => '2026-09-17', 'work_done' => 'Fan replaced', 'cost' => '600', 'expense_voucher_id' => $this->voucher->id], $this->manager);
    expect($second->fresh()->cost)->toBe('600.0000');

    $cancelled = $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'X'], $this->keeper);
    $this->records->cancel($cancelled, 'No fault', $this->keeper);
    expect(fn () => $this->records->setMoney($cancelled, ['cost' => '10'], $this->manager))->toThrow(ValidationException::class, 'was cancelled');

    // Screens: the keeper never sees the money, a Manager does; only the register's readers set it.
    $keeperView = $this->actingAs($this->keeper)->get("/assets/maintenance/{$record->id}")->assertOk()->original->getData()['page']['props'];
    $managerView = $this->actingAs($this->manager)->get("/assets/maintenance/{$record->id}")->assertOk()->original->getData()['page']['props'];
    expect($keeperView['record']['cost'])->toBeNull()
        ->and($keeperView['record']['voucher'])->toBeNull()
        ->and($keeperView['vouchers'])->toBe([])
        ->and($keeperView['sees_money'])->toBeFalse()
        ->and($managerView['record']['cost'])->toBe('900.50')
        ->and($managerView['record']['voucher']['number'])->toBe('EV-0001')
        ->and($managerView['vouchers'])->toHaveCount(1);

    $item = $this->actingAs($this->keeper)->get("/assets/items/{$this->printer->id}")->original->getData()['page']['props']['maintenance'];
    expect($item['records'][0]['cost'])->toBeNull();
    $item = $this->actingAs($this->manager)->get("/assets/items/{$this->printer->id}")->original->getData()['page']['props']['maintenance'];
    expect($item['records'][0]['cost'])->toBe('900.50');

    $this->actingAs($this->keeper)->post("/assets/maintenance/{$record->id}/money", ['cost' => '1'])->assertForbidden();
    $this->actingAs($this->manager)->post("/assets/maintenance/{$record->id}/money", ['cost' => '950', 'expense_voucher_id' => $this->voucher->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($record->fresh()->cost)->toBe('950.0000');
});

it('lets the holder report a problem from My assets, once, and nobody else', function () {
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper);

    $this->actingAs($this->staff)->post("/hrm/my-assets/{$this->printer->id}/report", ['problem' => 'Not mine'])->assertNotFound();
    $this->actingAs($this->keeper)->post("/hrm/my-assets/{$this->laptop->id}/report", ['problem' => 'Not mine'])->assertNotFound();
    $this->actingAs($this->staff)->post("/hrm/my-assets/{$this->laptop->id}/report", ['problem' => ''])->assertSessionHasErrors('problem');
    expect(fn () => $this->records->report($this->printer, 'Not mine', $this->staff))->toThrow(ValidationException::class, 'is not recorded as with you')
        ->and(fn () => $this->records->report($this->laptop, 'Not mine', $this->keeper))->toThrow(ValidationException::class, 'is not recorded as with you');

    // A check-up the keeper has open does not stop a fault being reported.
    $checkup = $this->records->open($this->laptop, ['kind' => 'inspection', 'problem' => 'Yearly check'], $this->keeper);

    $this->actingAs($this->staff)->post("/hrm/my-assets/{$this->laptop->id}/report", [
        'problem' => 'The battery dies in an hour',
        'photos' => [UploadedFile::fake()->image('battery.jpg')],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $record = AssetMaintenanceRecord::query()->where('kind', 'repair')->sole();
    expect($record->number)->toBe('MR-000002')
        ->and($record->kind->value)->toBe('repair')
        ->and($record->isOpen())->toBeTrue()
        ->and($record->reported_by)->toBe($this->staff->id)
        ->and($record->out_of_service)->toBeFalse()
        ->and($record->attachments()->count())->toBe(1)
        ->and($record->canAccessAttachments($this->staff))->toBeTrue()
        ->and($record->canAccessAttachments(($this->user)('Staff')))->toBeFalse()
        ->and($this->laptop->fresh()->status)->toBe(AssetStatus::InUse);

    // Once: a second report while the first is open is refused.
    $this->actingAs($this->staff)->post("/hrm/my-assets/{$this->laptop->id}/report", ['problem' => 'Still bad'])->assertSessionHasErrors('item');
    expect(AssetMaintenanceRecord::query()->count())->toBe(2);

    $mine = $this->actingAs($this->staff)->get('/hrm/my-assets')->assertOk()->original->getData()['page']['props']['items'];
    expect($mine[0]['open_report'])->toBe(['number' => 'MR-000002', 'reported_on' => '2026-09-17']);

    // Once it is dealt with, the holder sees that, and may report a new problem.
    $this->records->complete($record, ['done_on' => '2026-09-17', 'work_done' => 'New battery'], $this->keeper);
    $this->records->cancel($checkup, 'Next month', $this->keeper);
    expect($this->actingAs($this->staff)->get('/hrm/my-assets')->original->getData()['page']['props']['items'][0]['open_report'])->toBeNull();
    $this->actingAs($this->staff)->post("/hrm/my-assets/{$this->laptop->id}/report", ['problem' => 'Keyboard'])->assertSessionHasNoErrors();
    expect(AssetMaintenanceRecord::query()->count())->toBe(3)
        ->and($this->actingAs($this->staff)->get('/hrm/my-assets')->original->getData()['page']['props']['items'][0]['open_report']['number'])->toBe('MR-000003');
});

it('tells whoever keeps the register what waits, every day, and nothing when nothing waits', function () {
    Notification::fake();

    $this->artisan('assets:maintenance-due')->assertSuccessful();
    Notification::assertNothingSent();
    // (The command leaves the tenant scope when it finishes.)
    TenantScope::enter($this->business->id);

    $overdue = ($this->monthly)($this->printer, ['next_due_on' => '2026-09-10']);
    ($this->monthly)($this->laptop, ['title' => 'Update', 'next_due_on' => '2026-09-19']);
    ($this->monthly)($this->drone, ['title' => 'Far off', 'next_due_on' => '2026-11-01']);
    $this->records->open($this->drone, ['kind' => 'repair', 'problem' => 'Gimbal'], $this->keeper);
    $this->laptop->forceFill(['warranty_ends_on' => '2026-10-17'])->save();
    // The window's last day is in; the day after is not.
    $this->printer->forceFill(['service_ends_on' => '2026-10-17', 'warranty_ends_on' => '2026-10-18'])->save();
    $this->drone->forceFill(['warranty_ends_on' => '2026-09-16'])->save();
    $gone = ($this->register)('IT', 'Old desktop', ['warranty_ends_on' => '2026-10-01']);
    $gone->forceFill(['status' => AssetStatus::Disposed])->save();

    $this->artisan('assets:maintenance-due --dry-run')->expectsOutputToContain('would tell')->assertSuccessful();
    Notification::assertNothingSent();

    $this->artisan('assets:maintenance-due')->assertSuccessful();

    Notification::assertSentTo([$this->keeper, $this->manager], AssetMaintenanceDueNotification::class, function (AssetMaintenanceDueNotification $n) {
        expect($n->due)->toBe(2)
            ->and($n->overdue)->toBe(1)
            ->and($n->open)->toBe(1)
            ->and($n->ending)->toBe(2)
            ->and($n->summary())->toStartWith('2 maintenance jobs due (1 already overdue), 1 open fault or job, 2 warranties or service contracts ending within 30 days: BDG-PRN-0001 Clean the head (due 2026-09-10)');

        return true;
    });
    Notification::assertNotSentTo([$this->staff, $this->stranger], AssetMaintenanceDueNotification::class);

    // Another business hears nothing about this one.
    TenantScope::enter($this->other->id);
    $this->artisan('assets:maintenance-due', ['--business' => $this->other->id])->expectsOutputToContain('Other Co: 0 due, 0 open, 0 ending')->assertSuccessful();

    // The screens say the same.
    TenantScope::enter($this->business->id);
    $page = $this->actingAs($this->keeper)->get('/assets/maintenance')->assertOk()->original->getData()['page']['props'];
    expect(collect($page['due'])->pluck('id')->all())->toBe([$overdue->id, $overdue->id + 1])
        ->and($page['due'][0]['overdue'])->toBeTrue()
        ->and($page['open'])->toHaveCount(1)
        ->and(collect($page['ending'])->map(fn ($e) => "{$e['tag']} {$e['what']} {$e['ends_on']}")->all())->toBe([
            'BDG-IT-0001 Warranty 2026-10-17',
            'BDG-PRN-0001 Service contract 2026-10-17',
        ]);

    $work = $this->actingAs($this->keeper)->get('/work')->assertOk()->original->getData()['page']['props']['assets'];
    expect($work['maintenance_due'])->toBe(2)->and($work['open_repairs'])->toBe(1);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'assets:maintenance-due'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 8 * * *');
});

it('runs through the screens for the keeper', function () {
    $this->actingAs($this->staff)->get('/assets/maintenance')->assertForbidden();
    $this->actingAs($this->staff)->post('/assets/maintenance', ['item_id' => $this->printer->id, 'kind' => 'repair', 'problem' => 'X', 'reported_on' => '2026-09-17'])->assertForbidden();
    $this->actingAs($this->staff)->get("/assets/maintenance/plans/create?item={$this->printer->id}")->assertForbidden();

    $this->actingAs($this->keeper)->get("/assets/maintenance/plans/create?item={$this->printer->id}")->assertOk();
    $this->actingAs($this->keeper)->post('/assets/maintenance/plans', [
        'item_id' => $this->printer->id, 'title' => 'Clean the head', 'every_count' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-09-30', 'remind_days' => 3, 'party_id' => $this->shop->id,
    ])->assertRedirect("/assets/items/{$this->printer->id}")->assertSessionHasNoErrors();
    $plan = AssetMaintenancePlan::query()->sole();

    $this->actingAs($this->keeper)->get("/assets/maintenance/plans/{$plan->id}/edit")->assertOk();
    $this->actingAs($this->keeper)->put("/assets/maintenance/plans/{$plan->id}", [
        'title' => 'Clean the head', 'every_count' => 2, 'every_unit' => 'month', 'next_due_on' => '2026-09-30', 'remind_days' => 7,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->keeper)->post("/assets/maintenance/plans/{$plan->id}/active", ['active' => false])->assertRedirect();
    expect($plan->fresh()->is_active)->toBeFalse()->and($plan->fresh()->party_id)->toBeNull();

    $create = $this->actingAs($this->keeper)->get("/assets/maintenance/create?item={$this->printer->id}")->assertOk()->original->getData()['page']['props'];
    expect(collect($create['items'])->pluck('value')->all())->toBe([(string) $this->drone->id, (string) $this->laptop->id, (string) $this->printer->id])
        ->and($create['preselected']['item'])->toBe($this->printer->id);

    $this->actingAs($this->keeper)->post('/assets/maintenance', [
        'item_id' => $this->printer->id, 'kind' => 'repair', 'problem' => 'Paper jam', 'reported_on' => '2026-09-17', 'out_of_service' => true,
        'photos' => [UploadedFile::fake()->image('jam.jpg')],
    ])->assertRedirect('/assets/maintenance/1');
    $record = AssetMaintenanceRecord::query()->sole();
    expect($this->printer->fresh()->status)->toBe(AssetStatus::UnderRepair);

    $show = $this->actingAs($this->keeper)->get("/assets/maintenance/{$record->id}")->assertOk()->original->getData()['page']['props'];
    expect($show['record']['photos'])->toHaveCount(1)
        ->and($show['record']['status_before'])->toBe('In store');

    $this->actingAs($this->keeper)->post("/assets/maintenance/{$record->id}/photos", ['photos' => [UploadedFile::fake()->image('after.jpg')]])->assertRedirect();
    $this->actingAs($this->keeper)->post("/assets/maintenance/{$record->id}/complete", ['done_on' => '2026-09-17'])->assertSessionHasErrors('work_done');
    $this->actingAs($this->keeper)->post("/assets/maintenance/{$record->id}/complete", ['done_on' => '2026-09-17', 'work_done' => 'Roller cleaned', 'condition_after' => 'good'])->assertRedirect()->assertSessionHasNoErrors();
    expect($record->fresh()->state)->toBe(MaintenanceState::Done)
        ->and($this->printer->fresh()->status)->toBe(AssetStatus::InStore)
        ->and($record->attachments()->count())->toBe(2);

    $this->actingAs($this->keeper)->post("/assets/maintenance/{$record->id}/cancel", ['cancel_reason' => 'Late'])->assertSessionHasErrors('record');

    // A thing of another business is not in this register.
    $foreign = AssetItem::withoutGlobalScopes()->create(['business_id' => $this->other->id, 'asset_category_id' => $this->printer->asset_category_id, 'tag' => 'OTH-PRN-0999', 'sequence' => 999, 'name' => 'Theirs', 'office_id' => $this->office->id, 'created_by' => $this->keeper->id]);
    $this->actingAs($this->keeper)->post('/assets/maintenance', ['item_id' => $foreign->id, 'kind' => 'repair', 'problem' => 'X', 'reported_on' => '2026-09-17'])->assertNotFound();

    // The item page carries its plans and records; the item form its service contract.
    $this->actingAs($this->keeper)->put("/assets/items/{$this->printer->id}", [
        'name' => 'LaserJet', 'place' => "office:{$this->office->id}", 'status' => 'in_store', 'condition' => 'good',
        'service_ends_on' => '2027-03-31', 'service_party_id' => $this->shop->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $page = $this->actingAs($this->keeper)->get("/assets/items/{$this->printer->id}")->original->getData()['page']['props'];
    // ⚠ The history shows the Dhaka date, not the UTC day before it.
    $changes = collect($page['history'][0]['changes'])->keyBy('field');
    expect($changes['Service contract ends']['to'])->toBe('2027-03-31')
        ->and($changes['Serviced by']['to'])->toBe('Rahim (IDB Bhaban)');

    expect($page['item']['service_ends_on'])->toBe('2027-03-31')
        ->and($page['item']['service_party'])->toBe('Rahim (IDB Bhaban)')
        ->and($page['maintenance']['plans'])->toHaveCount(1)
        ->and($page['maintenance']['records'][0]['number'])->toBe('MR-000001');

    $foreignShop = AssetParty::withoutGlobalScopes()->create(['business_id' => $this->other->id, 'name' => 'Far away', 'kind' => 'repair_shop']);
    $this->actingAs($this->keeper)->put("/assets/items/{$this->printer->id}", [
        'name' => 'LaserJet', 'place' => "office:{$this->office->id}", 'status' => 'in_store', 'condition' => 'good', 'service_party_id' => $foreignShop->id,
    ])->assertSessionHasErrors('service_party_id');

    // A counted group has no maintenance section.
    expect($this->actingAs($this->keeper)->get("/assets/items/{$this->scissors->id}")->original->getData()['page']['props']['maintenance'])->toBeNull();
});
