<?php

use App\Models\User;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Support\AssetItemFile;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The things sheet (asset register bulk import, Joy 13 Oct 2026): download the
 * template, fill it, preview with every error listed, apply all or nothing.
 *
 * ⭐ A row may name who holds the thing today, and the import then records the
 * hand-over the keeper would have recorded. Nothing is invented: an unknown
 * category, place, employee, party or parent tag stops the row.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
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

    $this->owner = ($this->user)('Owner');
    $this->keeper = ($this->user)('Asset Keeper');
    $this->staff = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head office']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);

    $this->employee = function (string $first, string $code): Employee {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent',
            'status' => 'active', 'joining_date' => '2026-01-01',
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id, 'employee_id' => $employee->id, 'office_id' => $this->office->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $employee;
    };

    $this->rakib = ($this->employee)('Rakib', 'BDG-000016');
    $this->nazmul = ($this->employee)('Nazmul', 'BDG-000017');
    $this->freelancer = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Shuvo Photography', 'kind' => 'freelancer']);

    // A row in the template's columns, from only the columns that matter.
    $this->row = function (array $cells): string {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, array_map(fn (string $column) => (string) ($cells[$column] ?? ''), AssetItemFile::TEMPLATE));
        rewind($out);

        return rtrim(stream_get_contents($out), "\n");
    };

    $this->sheet = fn (array $rows, ?string $header = null) => UploadedFile::fake()->createWithContent(
        'things.csv',
        ($header ?? implode(',', AssetItemFile::TEMPLATE))."\n".implode("\n", array_map($this->row, $rows))."\n",
    );

    $this->preview = fn (User $as, UploadedFile $file) => $this->actingAs($as)
        ->post(route('assets.items.import.preview'), ['file' => $file])
        ->original->getData()['page']['props']['preview'];

    $this->apply = fn (User $as, array $preview) => $this->actingAs($as)
        ->post(route('assets.items.import.store'), ['token' => $preview['token'], 'hash' => $preview['hash']]);

    $this->messages = fn (array $problems) => collect($problems)->map(fn ($e) => "{$e['line']}:{$e['message']}")->all();

    $this->register = fn (string $code, string $name, array $overrides = []) => app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', $code)->sole(),
        array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
        $this->owner,
    )->first();
});

afterEach(fn () => Carbon::setTestNow());

it('downloads the template with its header and example rows, for keepers only', function () {
    $csv = $this->actingAs($this->keeper)->get(route('assets.items.import.template'))->streamedContent();
    $lines = explode("\n", trim(substr($csv, 3)));

    expect(substr($csv, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF))
        ->and($lines[0])->toBe(implode(',', AssetItemFile::TEMPLATE))
        ->and($lines)->toHaveCount(1 + count(AssetItemFile::EXAMPLES))
        ->and($lines[1])->toStartWith(',IT,Laptop,1,HP,"ProBook 450 G9",5CD2345XYZ,');

    $this->actingAs($this->staff)->get(route('assets.items.import.template'))->assertForbidden();
    $this->actingAs($this->staff)->get(route('assets.items.import'))->assertForbidden();
    $this->actingAs($this->keeper)->get(route('assets.items.import'))->assertOk();
});

it('lists every bad row in the preview and writes nothing', function () {
    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        ['category' => 'XYZ', 'name' => 'Mystery', 'place' => 'Head office'],
        ['category' => 'IT', 'name' => 'Laptop', 'place' => 'Head office', 'holder' => 'Nobody Known'],
        ['category' => 'IT', 'name' => 'Camera', 'place' => 'Head office', 'holder' => 'BDG-000016', 'outside_party' => 'Shuvo Photography'],
        ['category' => 'IT', 'name' => 'Phone A', 'place' => 'Head office', 'serial_number' => 'SN-1'],
        ['category' => 'IT', 'name' => 'Phone B', 'place' => 'Head office', 'serial_number' => 'sn-1'],
        ['category' => 'IT', 'name' => 'Mouse', 'place' => 'Head office', 'acquired_on' => '10/03/2025'],
        ['category' => 'IT', 'name' => 'Monitor', 'place' => 'Nowhere'],
        ['category' => 'IT', 'name' => 'Chairs', 'place' => 'Head office', 'quantity' => '3', 'serial_number' => 'SN-9'],
        ['category' => 'IT', 'name' => 'Keyboard', 'place' => 'Head office', 'status' => 'missing', 'condition' => 'broken'],
        ['category' => 'IT', 'name' => 'Cable', 'place' => 'Head office', 'part_of' => 'BDG-IT-9999'],
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'Gone', 'place' => 'Head office'],
    ]));

    $messages = ($this->messages)($preview['errors']);

    expect($messages[0])->toStartWith('2:category "XYZ" is not one of this business')
        ->and($messages[1])->toStartWith('3:holder "Nobody Known" is not an employee')
        ->and($messages[2])->toStartWith('4:A thing is with an employee or with an outside party, not both')
        ->and($messages[3])->toStartWith('6:serial_number sn-1 is already on line 5')
        ->and($messages[4])->toStartWith('7:acquired_on "10/03/2025" must be a date written as YYYY-MM-DD')
        ->and($messages[5])->toStartWith('8:place "Nowhere" is not an active office or warehouse')
        ->and($messages[6])->toStartWith('9:A serial number belongs to one thing')
        ->and($messages[7])->toStartWith('10:condition "broken" is not one of')
        ->and($messages[8])->toStartWith('10:status Missing is decided by a stock-take')
        ->and($messages[9])->toStartWith('11:part_of: BDG-IT-9999 is not a tagged thing')
        ->and($messages[10])->toStartWith('12:No thing with tag BDG-IT-0001 is on this register')
        ->and($preview['errors'])->toHaveCount(11)
        ->and($preview['creates'])->toHaveCount(1)
        ->and(AssetItem::query()->count())->toBe(0);

    // One error and the apply is refused: nothing written.
    ($this->apply)($this->keeper, $preview)->assertSessionHasErrors('file');
    expect(AssetItem::query()->count())->toBe(0)->and(AssetMovement::query()->count())->toBe(0);

    // A header the reader does not understand is refused before any row.
    $this->actingAs($this->keeper)->post(route('assets.items.import.preview'), [
        'file' => ($this->sheet)([['category' => 'IT', 'name' => 'Laptop']], 'category,name,serial'),
    ])->assertSessionHasErrors('file');
});

it('registers the things and records the hand-overs the sheet describes', function () {
    $preview = ($this->preview)($this->owner, ($this->sheet)([
        ['category' => 'it', 'name' => 'Laptop', 'brand' => 'HP', 'serial_number' => '5CD2345XYZ', 'place' => 'head office', 'area' => 'Desk 3',
            'status' => 'in_use', 'condition' => 'good', 'acquired_on' => '2025-03-10', 'purchase_price' => '85000', 'warranty_ends_on' => '2027-03-10',
            'holder' => 'BDG-000016', 'note' => "Rakibul's work laptop"],
        ['category' => 'Furniture', 'name' => 'Office chair', 'quantity' => '3', 'place' => 'office:Head office', 'status' => 'in_store', 'holder' => 'Nazmul Test'],
        ['category' => 'SML', 'name' => 'Scissors', 'quantity' => '4', 'place' => 'Savar Warehouse', 'holder' => 'BDG-000016'],
        ['category' => 'CAM', 'name' => 'DSLR camera', 'place' => 'Head office', 'status' => 'with_outside_party', 'outside_party' => 'shuvo photography', 'acquired_on' => '2024-06-01'],
        ['category' => 'IT', 'name' => 'Spare mouse', 'place' => 'Head office'],
    ]));

    expect($preview['errors'])->toBe([])
        ->and($preview['warnings'])->toHaveCount(1)
        ->and($preview['warnings'][0]['message'])->toStartWith('status In store contradicts the hand-over to Nazmul Test')
        // Six tagged things and the counted group's own tag.
        ->and($preview['tags'])->toBe(7)
        ->and(collect($preview['creates'])->pluck('makes')->all())->toBe(['1 tag', '3 tags', '1 counted group of 4 piece(s)', '1 tag', '1 tag'])
        ->and($preview['creates'][0]['hands_to'])->toBe('would issue to Rakib Test (BDG-000016)')
        ->and($preview['creates'][3]['hands_to'])->toBe('would send out to Shuvo Photography')
        ->and($preview['creates'][4]['hands_to'])->toBeNull()
        // ⚠ The preview wrote nothing.
        ->and(AssetItem::query()->count())->toBe(0);

    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors()->assertRedirect(route('assets.items.index'));

    $laptop = AssetItem::query()->where('tag', 'BDG-IT-0001')->sole();
    expect(AssetItem::query()->count())->toBe(7)
        ->and($laptop->custodian_employee_id)->toBe($this->rakib->id)
        ->and($laptop->status)->toBe(AssetStatus::InUse)
        ->and($laptop->custody_since->toDateString())->toBe('2025-03-10')
        ->and($laptop->acknowledged_at)->toBeNull()
        ->and($laptop->office_id)->toBe($this->office->id)
        ->and($laptop->area)->toBe('Desk 3')
        ->and($laptop->purchase_price)->toBe('85000.0000')
        ->and($laptop->warranty_ends_on->toDateString())->toBe('2027-03-10');

    $chairs = AssetItem::query()->where('name', 'Office chair')->orderBy('tag')->get();
    expect($chairs->pluck('tag')->all())->toBe(['BDG-FURN-0001', 'BDG-FURN-0002', 'BDG-FURN-0003'])
        ->and($chairs->pluck('custodian_employee_id')->unique()->all())->toBe([$this->nazmul->id])
        ->and($chairs->pluck('status')->unique()->all())->toBe([AssetStatus::InUse]);

    $scissors = AssetItem::query()->where('tag', 'BDG-SML-0001')->sole();
    expect($scissors->quantity)->toBe(4)
        ->and(AssetHolding::query()->where('asset_item_id', $scissors->id)->pluck('quantity', 'holder_key')->all())->toBe(["e{$this->rakib->id}" => 4]);

    $camera = AssetItem::query()->where('tag', 'BDG-CAM-0001')->sole();
    expect($camera->custodian_party_id)->toBe($this->freelancer->id)
        ->and($camera->status)->toBe(AssetStatus::WithOutsideParty)
        ->and($camera->custody_since->toDateString())->toBe('2024-06-01');

    expect(AssetItem::query()->where('name', 'Spare mouse')->sole()->custodyLabel())->toBe('Nobody, in store');

    // The hand-overs are real documents, dated by the sheet, marked as imported.
    $movements = AssetMovement::query()->orderBy('id')->get();
    expect($movements)->toHaveCount(4)
        ->and($movements->pluck('kind')->all())->toBe([AssetMovementKind::Issue, AssetMovementKind::Issue, AssetMovementKind::Issue, AssetMovementKind::SendOut])
        ->and($movements->pluck('note')->unique()->all())->toBe(['Imported'])
        ->and($movements[0]->moved_on->toDateString())->toBe('2025-03-10')
        ->and($movements[1]->to_employee_id)->toBe($this->nazmul->id)
        ->and($movements[1]->lines()->count())->toBe(3)
        ->and($movements[2]->moved_on->toDateString())->toBe('2026-10-13')
        ->and($movements[3]->to_party_id)->toBe($this->freelancer->id);

    // The staged file is gone: the same token cannot be applied twice.
    ($this->apply)($this->owner, $preview)->assertSessionHasErrors('file');
    expect(AssetItem::query()->count())->toBe(7);
});

it('corrects a thing named by its tag and never moves it', function () {
    $laptop = ($this->register)('IT', 'ThinkPad', ['serial_number' => 'SN-OLD', 'area' => 'Desk 1']);
    $other = ($this->register)('IT', 'Mouse', ['serial_number' => 'SN-MOUSE']);
    $scissors = ($this->register)('SML', 'Scissors', ['tracking' => 'quantity', 'quantity' => 4]);
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->rakib->id], $this->owner, '2026-10-01');

    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        // The holder written as the export writes it: the same person, so no hand-over is implied.
        ['tag' => 'bdg-it-0001', 'category' => 'IT', 'name' => 'ThinkPad T470s', 'place' => 'Head office', 'area' => 'Desk 2', 'serial_number' => 'SN-NEW', 'condition' => 'fair', 'holder' => 'BDG-000016'],
        ['tag' => 'BDG-IT-0002', 'category' => 'FURN', 'name' => 'Mouse', 'place' => 'Head office'],
        ['tag' => 'BDG-IT-0002', 'category' => 'IT', 'name' => 'Mouse', 'place' => 'Head office', 'serial_number' => 'SN-OLD', 'holder' => 'BDG-000017'],
        ['tag' => 'BDG-IT-0002', 'category' => 'IT', 'name' => 'Mouse', 'place' => 'Head office', 'quantity' => '2', 'part_of' => 'BDG-IT-0002'],
        ['tag' => 'BDG-SML-0001', 'category' => 'SML', 'name' => 'Scissors', 'place' => 'Head office', 'quantity' => '6'],
    ]));

    $messages = ($this->messages)($preview['errors']);
    expect($messages)->toHaveCount(7)
        ->and($messages[0])->toStartWith('3:BDG-IT-0002 is in category Computers and IT')
        // The same tag on three rows: the second and third are refused as such too.
        ->and($messages[1])->toStartWith('4:tag BDG-IT-0002 is already on line 3')
        ->and($messages[2])->toStartWith('4:BDG-IT-0002 is with Nobody, in store. The sheet does not move')
        ->and($messages[3])->toStartWith('4:serial_number SN-OLD is already on BDG-IT-0001')
        ->and($messages[4])->toStartWith('5:tag BDG-IT-0002 is already on line 3')
        ->and($messages[5])->toStartWith('5:BDG-IT-0002 is one tagged thing: quantity must be 1 or blank')
        ->and($messages[6])->toStartWith('5:part_of: BDG-IT-0002 cannot be part of itself')
        ->and($preview['updates'])->toHaveCount(2)
        ->and(collect($preview['updates'][0]['changes'])->pluck('to', 'field')->all())->toBe(['name' => 'ThinkPad T470s', 'serial_number' => 'SN-NEW', 'area' => 'Desk 2', 'condition' => 'fair'])
        ->and(collect($preview['updates'][1]['changes'])->pluck('to', 'field')->all())->toBe(['quantity' => '6']);

    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad T470s', 'place' => 'Head office', 'area' => 'Desk 2', 'serial_number' => 'SN-NEW', 'condition' => 'fair', 'holder' => 'BDG-000016'],
        ['tag' => 'BDG-SML-0001', 'category' => 'SML', 'name' => 'Scissors', 'place' => 'Head office', 'quantity' => '6'],
    ]));
    expect($preview['errors'])->toBe([]);
    ($this->apply)($this->keeper, $preview)->assertSessionHasNoErrors();

    $laptop = $laptop->fresh();
    expect($laptop->name)->toBe('ThinkPad T470s')
        ->and($laptop->serial_number)->toBe('SN-NEW')
        ->and($laptop->area)->toBe('Desk 2')
        ->and($laptop->condition->value)->toBe('fair')
        ->and($laptop->custodian_employee_id)->toBe($this->rakib->id)
        ->and($laptop->status)->toBe(AssetStatus::InUse)
        ->and($scissors->fresh()->quantity)->toBe(6)
        ->and(AssetHolding::query()->where('asset_item_id', $scissors->id)->sum('quantity'))->toBe(6)
        ->and($other->fresh()->name)->toBe('Mouse')
        ->and(AssetItem::query()->count())->toBe(3)
        ->and(AssetMovement::query()->count())->toBe(1);
});

it('refuses a price from a keeper who may not see them, and takes it from the owner', function () {
    $laptop = ($this->register)('IT', 'ThinkPad', ['purchase_price' => '70000']);

    // ⚠ An error, not a warning that drops the price silently (ruling 7): the
    // keeper would otherwise believe the 12,000 was recorded.
    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        ['category' => 'IT', 'name' => 'Monitor', 'place' => 'Head office', 'purchase_price' => '12000'],
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'place' => 'Head office', 'purchase_price' => '99999'],
        ['category' => 'IT', 'name' => 'Cable', 'place' => 'Head office'],
    ]));

    expect(($this->messages)($preview['errors']))->toBe(['2:purchase_price is not yours to set; leave it blank.', '3:purchase_price is not yours to set; leave it blank.'])
        ->and($preview['warnings'])->toBe([])
        ->and($preview['creates'])->toHaveCount(1);

    ($this->apply)($this->keeper, $preview)->assertSessionHasErrors('file');
    expect(AssetItem::query()->count())->toBe(1)
        ->and($laptop->fresh()->purchase_price)->toBe('70000.0000');

    // The keeper's sheet with the column blank goes through and touches no price.
    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        ['category' => 'IT', 'name' => 'Monitor', 'place' => 'Head office'],
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'place' => 'Head office'],
    ]));
    expect($preview['errors'])->toBe([])->and($preview['updates'][0]['changes'])->toBe([]);
    ($this->apply)($this->keeper, $preview)->assertSessionHasNoErrors();
    expect(AssetItem::query()->where('name', 'Monitor')->sole()->purchase_price)->toBeNull()
        ->and($laptop->fresh()->purchase_price)->toBe('70000.0000');

    // The same file from the Owner sets it, and a bad amount is an error.
    $preview = ($this->preview)($this->owner, ($this->sheet)([
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'place' => 'Head office', 'purchase_price' => '72,500.50'],
        ['category' => 'IT', 'name' => 'Cable', 'place' => 'Head office', 'purchase_price' => 'twelve'],
    ]));
    expect(($this->messages)($preview['errors']))->toBe(['3:purchase_price "twelve" is not a number.'])
        ->and(collect($preview['updates'][0]['changes'])->pluck('to', 'field')->all())->toBe(['purchase_price' => '72500.50']);

    $preview = ($this->preview)($this->owner, ($this->sheet)([
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'place' => 'Head office', 'purchase_price' => '72,500.50'],
    ]));
    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors();
    expect($laptop->fresh()->purchase_price)->toBe('72500.5000');
});

it('leaves a blank cell unchanged on a row with a tag', function () {
    $laptop = ($this->register)('IT', 'ThinkPad', [
        'brand' => 'Lenovo', 'model' => 'T470s', 'serial_number' => 'SN-1', 'specification' => 'i5, 16 GB', 'area' => 'Desk 1', 'condition' => 'fair',
        'acquired_on' => '2025-01-05', 'purchase_price' => '70000', 'warranty_ends_on' => '2027-01-05', 'note' => 'Keep the charger with it',
    ]);
    $charger = ($this->register)('IT', 'Charger', ['parent_id' => $laptop->id, 'warehouse_id' => $this->warehouse->id, 'office_id' => null]);

    // ⚠ The export, with brand and note blanked out and nothing else touched:
    // the way a sheet comes back from Excel after a two-cell correction.
    $csv = $this->actingAs($this->owner)->get(route('assets.items.export'))->streamedContent();
    $lines = explode("\n", trim(substr($csv, 3)));
    expect($lines[1])->toBe('BDG-IT-0001,IT,ThinkPad,1,Lenovo,T470s,SN-1,"i5, 16 GB","Head office","Desk 1",in_store,fair,,2025-01-05,,70000.00,2027-01-05,,,"Keep the charger with it"');
    $lines[1] = 'BDG-IT-0001,IT,ThinkPad,1,,T470s,SN-1,"i5, 16 GB","Head office","Desk 1",in_store,fair,,2025-01-05,,70000.00,2027-01-05,,,';

    $preview = ($this->preview)($this->owner, UploadedFile::fake()->createWithContent('things.csv', implode("\n", $lines)."\n"));
    expect($preview['errors'])->toBe([])
        ->and(collect($preview['updates'])->pluck('changes')->flatten()->all())->toBe([]);
    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors();

    $laptop = $laptop->fresh();
    expect($laptop->brand)->toBe('Lenovo')->and($laptop->note)->toBe('Keep the charger with it');

    // Every optional cell blank, from the keeper: the thing is exactly as it was,
    // place and part included. Only a filled cell changes anything.
    $preview = ($this->preview)($this->keeper, ($this->sheet)([
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad'],
        ['tag' => 'BDG-IT-0002', 'category' => 'IT', 'name' => 'Charger 65W'],
    ]));
    expect($preview['errors'])->toBe([])
        ->and($preview['updates'][0]['changes'])->toBe([])
        ->and(collect($preview['updates'][1]['changes'])->pluck('to', 'field')->all())->toBe(['name' => 'Charger 65W']);
    ($this->apply)($this->keeper, $preview)->assertSessionHasNoErrors();

    $laptop = $laptop->fresh();
    expect($laptop->brand)->toBe('Lenovo')
        ->and($laptop->model)->toBe('T470s')
        ->and($laptop->serial_number)->toBe('SN-1')
        ->and($laptop->specification)->toBe('i5, 16 GB')
        ->and($laptop->office_id)->toBe($this->office->id)
        ->and($laptop->area)->toBe('Desk 1')
        ->and($laptop->condition->value)->toBe('fair')
        ->and($laptop->acquired_on->toDateString())->toBe('2025-01-05')
        ->and($laptop->purchase_price)->toBe('70000.0000')
        ->and($laptop->warranty_ends_on->toDateString())->toBe('2027-01-05')
        ->and($laptop->note)->toBe('Keep the charger with it')
        ->and($charger->fresh()->name)->toBe('Charger 65W')
        ->and($charger->fresh()->parent_id)->toBe($laptop->id)
        ->and($charger->fresh()->warehouse_id)->toBe($this->warehouse->id);
});

it('refuses a row that would move a thing or change its status, and the same tag twice', function () {
    $laptop = ($this->register)('IT', 'ThinkPad');
    $camera = ($this->register)('CAM', 'DSLR');
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->rakib->id], $this->owner, '2026-10-01');

    $preview = ($this->preview)($this->owner, ($this->sheet)([
        // Another place: a transfer, not a correction.
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'place' => 'Savar Warehouse', 'holder' => 'BDG-000016'],
        // In store while Rakib holds it: a return, not a correction.
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad', 'status' => 'in_store', 'holder' => 'BDG-000016'],
        // Under repair typed on a thing in store: a send-out, not a correction.
        ['tag' => 'BDG-CAM-0001', 'category' => 'CAM', 'name' => 'DSLR', 'status' => 'under_repair'],
        // A different holder: a hand-over (already refused before 13 Oct).
        ['tag' => 'BDG-CAM-0001', 'category' => 'CAM', 'name' => 'DSLR', 'holder' => 'BDG-000017'],
    ]));

    expect(($this->messages)($preview['errors']))->toBe([
        '2:BDG-IT-0001 is at Head office. Record a transfer; the sheet cannot move a thing.',
        '3:tag BDG-IT-0001 is already on line 2. One row per thing.',
        "3:BDG-IT-0001 is In use, not In store. Record a hand-over; the sheet cannot change a thing's status.",
        "4:BDG-CAM-0001 is In store, not Under repair. Record a hand-over; the sheet cannot change a thing's status.",
        '5:tag BDG-CAM-0001 is already on line 4. One row per thing.',
        '5:BDG-CAM-0001 is with Nobody, in store. The sheet does not move a thing already registered: record a hand-over.',
    ]);
    ($this->apply)($this->owner, $preview)->assertSessionHasErrors('file');

    // The same place, the same status and the same holder, written as the
    // export writes them, pass; a correction next to them is applied.
    $preview = ($this->preview)($this->owner, ($this->sheet)([
        ['tag' => 'BDG-IT-0001', 'category' => 'IT', 'name' => 'ThinkPad T470s', 'place' => 'office:Head office', 'status' => 'in_use', 'holder' => 'BDG-000016'],
        ['tag' => 'BDG-CAM-0001', 'category' => 'CAM', 'name' => 'DSLR', 'place' => 'Head office', 'status' => 'in_store', 'area' => 'Studio'],
    ]));
    expect($preview['errors'])->toBe([]);
    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors();
    expect($laptop->fresh()->name)->toBe('ThinkPad T470s')
        ->and($laptop->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($camera->fresh()->area)->toBe('Studio')
        ->and(AssetMovement::query()->count())->toBe(1);
});

it('guards formulas in the export and reads them back unchanged', function () {
    // ⚠ OWASP CSV injection: a cell starting with = + - @ runs in Excel.
    $laptop = ($this->register)('IT', '=HYPERLINK("http://evil.example","Open me")', ['brand' => '+Lenovo', 'model' => '-T470s', 'area' => '@Desk', 'note' => "O'Brien's desk"]);

    $csv = $this->actingAs($this->owner)->get(route('assets.items.export'))->streamedContent();
    $lines = explode("\n", trim(substr($csv, 3)));

    expect($lines[1])->toBe('BDG-IT-0001,IT,"\'=HYPERLINK(""http://evil.example"",""Open me"")",1,\'+Lenovo,\'-T470s,,,"Head office",\'@Desk,in_store,good,,,,,,,,"O\'Brien\'s desk"');

    // Back in: the guard comes off, the names are what they were, nothing changes.
    $preview = ($this->preview)($this->owner, UploadedFile::fake()->createWithContent('things.csv', $csv));
    expect($preview['errors'])->toBe([])
        ->and($preview['updates'])->toHaveCount(1)
        ->and($preview['updates'][0]['changes'])->toBe([]);
    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors();

    $laptop = $laptop->fresh();
    expect($laptop->name)->toBe('=HYPERLINK("http://evil.example","Open me")')
        ->and($laptop->brand)->toBe('+Lenovo')
        ->and($laptop->model)->toBe('-T470s')
        ->and($laptop->area)->toBe('@Desk')
        ->and($laptop->note)->toBe("O'Brien's desk");
});

it('refuses a file that changed since the preview', function () {
    $preview = ($this->preview)($this->keeper, ($this->sheet)([['category' => 'IT', 'name' => 'Laptop', 'place' => 'Head office']]));

    Storage::disk('local')->put('imports/'.$this->business->id.'/'.$preview['token'].'.csv', "category,name,place\nIT,Stolen laptop,Head office\n");

    ($this->apply)($this->keeper, $preview)->assertSessionHasErrors('file');
    expect(AssetItem::query()->count())->toBe(0);
});

it('exports in the template columns so the file round-trips', function () {
    $laptop = ($this->register)('IT', 'ThinkPad', ['serial_number' => 'SN-1', 'brand' => 'Lenovo', 'purchase_price' => '70000', 'acquired_on' => '2025-01-05', 'warranty_ends_on' => '2027-01-05', 'note' => 'Bangla: ল্যাপটপ']);
    ($this->register)('IT', 'Charger', ['parent_id' => $laptop->id, 'warehouse_id' => $this->warehouse->id, 'office_id' => null]);
    ($this->register)('SML', 'Scissors', ['tracking' => 'quantity', 'quantity' => 4]);
    $camera = ($this->register)('CAM', 'DSLR');
    $disposed = ($this->register)('IT', 'Dead printer');
    $disposed->forceFill(['status' => AssetStatus::Disposed])->save();
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->rakib->id], $this->owner, '2026-10-01');
    app(MoveAssets::class)->handle(AssetMovementKind::SendOut, [['item' => $camera]], ['party_id' => $this->freelancer->id], $this->owner, '2026-10-02');

    $this->actingAs($this->staff)->get(route('assets.items.export'))->assertForbidden();

    $csv = $this->actingAs($this->owner)->get(route('assets.items.export'))->streamedContent();
    $lines = explode("\n", trim(substr($csv, 3)));

    expect(substr($csv, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF))
        ->and($lines[0])->toBe(implode(',', AssetItemFile::TEMPLATE))
        ->and($lines)->toHaveCount(5)
        ->and($lines[1])->toBe('BDG-CAM-0001,CAM,DSLR,1,,,,,"Head office",,with_outside_party,good,,,,,,,"Shuvo Photography",')
        ->and($lines[2])->toBe('BDG-IT-0001,IT,ThinkPad,1,Lenovo,,SN-1,,"Head office",,in_use,good,,2025-01-05,,70000.00,2027-01-05,BDG-000016,,"Bangla: ল্যাপটপ"')
        ->and($lines[3])->toBe('BDG-IT-0002,IT,Charger,1,,,,,"Savar Warehouse",,in_store,good,BDG-IT-0001,,,,,,,')
        ->and($lines[4])->toBe('BDG-SML-0001,SML,Scissors,4,,,,,"Head office",,in_store,good,,,,,,,,');

    // The keeper's export carries no price.
    $keeperLines = explode("\n", trim(substr($this->actingAs($this->keeper)->get(route('assets.items.export'))->streamedContent(), 3)));
    expect($keeperLines[2])->toBe('BDG-IT-0001,IT,ThinkPad,1,Lenovo,,SN-1,,"Head office",,in_use,good,,2025-01-05,,,2027-01-05,BDG-000016,,"Bangla: ল্যাপটপ"');

    // Uploaded unchanged, the export corrects nothing and moves nothing.
    $preview = ($this->preview)($this->owner, UploadedFile::fake()->createWithContent('things.csv', $csv));
    expect($preview['errors'])->toBe([])
        ->and($preview['creates'])->toBe([])
        ->and($preview['updates'])->toHaveCount(4)
        ->and(collect($preview['updates'])->pluck('changes')->flatten()->all())->toBe([]);

    ($this->apply)($this->owner, $preview)->assertSessionHasNoErrors();
    expect(AssetItem::query()->count())->toBe(5)
        ->and(AssetMovement::query()->count())->toBe(2)
        ->and($laptop->fresh()->custodian_employee_id)->toBe($this->rakib->id)
        ->and($laptop->fresh()->purchase_price)->toBe('70000.0000');
});
