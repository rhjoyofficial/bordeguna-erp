<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\Actions\SaveCompanyAccount;
use App\Modules\Assets\Actions\SaveMaintenancePlan;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetDiscrepancy;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Assets overview (module redesign, 15 October 2026): one page that says
 * what the business holds, where it is, what needs somebody, and (for those
 * who may see it) what the accounts and the books say.
 *
 * ⭐ Every number is read from the same tables the lists read, and every card
 * shows only with the permission its list needs: an Asset Keeper sees the
 * things and no money; a hub reader sees the accounts and no things.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    Storage::fake('local');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->bionic = Business::create(['name' => 'Bionic Garden', 'slug' => 'bionic-garden', 'fiscal_year_start_month' => 7]);

    foreach ([$this->business, $this->bionic] as $business) {
        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $business->id]);
        }
    }

    TenantScope::enter($this->business->id);
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

    // A role holding ONE permission each, to prove the page renders with any subset.
    Role::create(['name' => 'Hub reader', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('assets.accounts.view');
    Role::create(['name' => 'Books reader', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('assets.view');

    $this->owner = ($this->user)('Owner');
    // The Owner belongs to Bionic too, so a Bionic account can be shared with us in the fixture.
    $this->owner->businesses()->attach($this->bionic->id);
    $this->manager = ($this->user)('Manager');
    $this->keeper = ($this->user)('Asset Keeper');
    $this->custodian = ($this->user)('IT Custodian');
    $this->staff = ($this->user)('Staff');
    $this->hubReader = ($this->user)('Hub reader');
    $this->booksReader = ($this->user)('Books reader');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);

    $this->rakib = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Rakib', 'last_name' => 'Test', 'employment_type' => 'permanent',
        'status' => 'active', 'joining_date' => '2026-01-01',
    ]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->rakib->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);
    $this->shop = AssetParty::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'organisation' => 'Rahim Computer Care', 'kind' => 'repair_shop']);

    $this->register = fn (string $code, string $name, array $overrides = []) => app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', $code)->sole(),
        array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
        $this->keeper,
    )->first();

    $this->page = fn (User $as) => $this->actingAs($as)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/assets/overview');

    $this->props = fn (User $as) => ($this->page)($as)->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

/** The register as the fixture sees it: five things kept, one disposed, each in a different state. */
function overviewThings(): array
{
    $t = test();
    $laptop = ($t->register)('IT', 'ThinkPad', ['warranty_ends_on' => '2026-10-30']);
    $printer = ($t->register)('PRN', 'LaserJet', ['service_ends_on' => '2026-12-01']);
    $camera = ($t->register)('CAM', 'Canon R50');
    $chair = ($t->register)('FURN', 'Desk chair', ['office_id' => null, 'warehouse_id' => $t->warehouse->id, 'condition' => 'poor', 'warranty_ends_on' => '2026-10-20', 'service_ends_on' => '2026-11-01']);
    $scissors = ($t->register)('SML', 'Scissors', ['tracking' => 'quantity', 'quantity' => 5]);
    $fan = ($t->register)('OFF', 'Old fan');
    $fan->forceFill(['status' => AssetStatus::Disposed])->save();

    $move = app(MoveAssets::class);
    // Rakib holds the laptop; the printer went to the shop and is five days late back; the camera is on its way to the warehouse.
    $move->handle(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $t->rakib->id], $t->keeper, '2026-10-01');
    $move->handle(AssetMovementKind::SendOut, [['item' => $printer]], ['party_id' => $t->shop->id], $t->keeper, '2026-10-01', 'Paper jam', '2026-10-10');
    $move->handle(AssetMovementKind::Transfer, [['item' => $camera]], ['warehouse_id' => $t->warehouse->id], $t->keeper, '2026-10-14');

    // Routine care three days overdue on the laptop; a repair open on the chair.
    app(SaveMaintenancePlan::class)->save($laptop, ['title' => 'Clean the fan', 'every_count' => 1, 'every_unit' => 'month', 'next_due_on' => '2026-10-12', 'remind_days' => 3], $t->keeper);
    app(RecordMaintenance::class)->open($chair, ['kind' => 'repair', 'problem' => 'Wheel broken', 'reported_on' => '2026-10-10'], $t->keeper);

    // One gap an earlier stock-take left unsettled.
    $count = AssetCount::create(['business_id' => $t->business->id, 'number' => 'AC-000001', 'office_id' => $t->office->id, 'status' => 'closed', 'started_by' => $t->keeper->id, 'closed_by' => $t->keeper->id, 'closed_at' => '2026-10-02 10:00:00']);
    AssetDiscrepancy::create(['business_id' => $t->business->id, 'asset_count_id' => $count->id, 'asset_item_id' => $scissors->id, 'kind' => 'short', 'quantity' => 1, 'responsible_user_id' => $t->keeper->id, 'state' => 'open']);

    return compact('laptop', 'printer', 'camera', 'chair', 'scissors');
}

/** Three accounts of our own (one closed) and one Bionic shares with us. */
function overviewAccounts(): void
{
    $t = test();
    $save = app(SaveCompanyAccount::class);
    $base = ['provider' => null, 'identifier' => null, 'url' => null, 'registered_as' => null, 'responsible_employee_id' => null, 'two_factor_method' => 'none',
        'two_factor_employee_id' => null, 'renewal_cycle' => 'yearly', 'renewal_cost' => null, 'renewal_money_account_id' => null, 'recovery_email' => null, 'recovery_phone' => null, 'status' => 'active', 'note' => null];

    $save->create($base + ['kind' => 'hosting', 'name' => 'Shop hosting', 'renews_on' => '2026-10-10'], $t->owner);
    $save->create($base + ['kind' => 'domain', 'name' => 'Main domain', 'renews_on' => '2026-11-01'], $t->owner);
    $save->create(['kind' => 'email', 'name' => 'Orders inbox', 'renews_on' => null, 'renewal_cycle' => 'none'] + $base, $t->owner);
    $save->create(['kind' => 'saas', 'name' => 'Old tool', 'renews_on' => '2026-10-01', 'status' => 'closed'] + $base, $t->owner);

    TenantScope::enter($t->bionic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($t->bionic->id);
    $save->create($base + ['kind' => 'saas', 'name' => 'Group payroll tool', 'renews_on' => '2027-01-01', 'shared_with' => [$t->business->id]], $t->owner);
    TenantScope::enter($t->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($t->business->id);
}

/** A van two months into its life and a desk whose cost never reached the books. */
function overviewBooks(): void
{
    $t = test();
    $vehicles = Account::create(['business_id' => $t->business->id, 'code' => '1230', 'name' => 'Vehicles', 'type' => AccountType::Asset, 'is_postable' => true, 'is_active' => true]);

    $van = FixedAsset::create([
        'business_id' => $t->business->id, 'asset_code' => 'VAN-01', 'name' => 'Delivery van', 'account_id' => $vehicles->id,
        'acquired_on' => '2026-07-01', 'cost' => '1200000', 'salvage_value' => '120000', 'useful_life_months' => 36,
        'depreciation_starts_on' => '2026-08-01', 'cost_on_books' => FixedAsset::COST_BY_ACQUISITION, 'created_by' => $t->owner->id,
    ]);
    foreach ([8, 9] as $month) {
        FixedAssetDepreciation::create(['business_id' => $t->business->id, 'fixed_asset_id' => $van->id, 'year' => 2026, 'month' => $month, 'amount' => '30000', 'charged_on' => "2026-{$month}-30"]);
    }
    FixedAsset::create([
        'business_id' => $t->business->id, 'asset_code' => 'DESK-01', 'name' => 'Boardroom desk', 'account_id' => $vehicles->id,
        'acquired_on' => '2026-07-01', 'cost' => '50000', 'salvage_value' => '0', 'useful_life_months' => 60,
        'depreciation_starts_on' => '2026-08-01', 'cost_on_books' => null, 'created_by' => $t->owner->id,
    ]);
}

// ── Gates ───────────────────────────────────────────────────────────────────

it('opens for anyone who may see one of the three registers, and for nobody else', function () {
    ($this->page)($this->staff)->assertForbidden();

    foreach ([$this->owner, $this->manager, $this->keeper, $this->custodian, $this->hubReader, $this->booksReader] as $user) {
        ($this->page)($user)->assertOk();
    }
});

it('hands each card only to the permission its list needs', function () {
    $keeper = ($this->props)($this->keeper);
    expect($keeper['can'])->toBe(['things' => true, 'accounts' => false, 'books' => false, 'manage_things' => true])
        ->and($keeper['things'])->not->toBeNull()
        ->and($keeper['accounts'])->toBeNull()
        ->and($keeper['books'])->toBeNull();

    $custodian = ($this->props)($this->custodian);
    expect($custodian['can'])->toBe(['things' => true, 'accounts' => true, 'books' => false, 'manage_things' => true])
        ->and($custodian['accounts'])->not->toBeNull()
        ->and($custodian['books'])->toBeNull();

    $hub = ($this->props)($this->hubReader);
    expect($hub['can'])->toBe(['things' => false, 'accounts' => true, 'books' => false, 'manage_things' => false])
        ->and($hub['things'])->toBeNull()
        ->and($hub['accounts'])->not->toBeNull()
        ->and($hub['needs'])->toBe([]);

    $books = ($this->props)($this->booksReader);
    expect($books['can'])->toBe(['things' => false, 'accounts' => false, 'books' => true, 'manage_things' => false])
        ->and($books['things'])->toBeNull()
        ->and($books['books'])->not->toBeNull();

    $manager = ($this->props)($this->manager);
    expect($manager['can'])->toBe(['things' => true, 'accounts' => true, 'books' => true, 'manage_things' => true]);
});

// ── The things ──────────────────────────────────────────────────────────────

it('counts the things kept, their pieces, and splits them by status and shape', function () {
    overviewThings();
    $things = ($this->props)($this->keeper)['things'];

    // Five kept (the disposed fan left out), nine pieces (the scissors are five).
    expect($things['count'])->toBe(5)
        ->and($things['pieces'])->toBe(9)
        ->and(collect($things['statuses'])->pluck('count', 'value')->all())->toBe([
            'in_use' => 1, 'in_store' => 3, 'under_repair' => 1, 'with_outside_party' => 0, 'missing' => 0,
        ])
        ->and(collect($things['statuses'])->firstWhere('value', 'in_store')['label'])->toBe('In store')
        ->and(collect($things['statuses'])->firstWhere('value', 'in_use')['url'])->toBe('/assets/items?status=in_use')
        ->and($things['damaged_or_poor'])->toBe(['count' => 1, 'url' => '/assets/items?condition=poor'])
        ->and($things['idle'])->toBe(['count' => 2, 'url' => '/assets/items?idle=1']);
});

it('lists what needs somebody, each with its count and the list it opens', function () {
    overviewThings();
    overviewAccounts();
    $needs = collect(($this->props)($this->manager)['needs'])->keyBy('key');

    expect($needs->map(fn (array $row) => [$row['count'], $row['url']])->all())->toBe([
        'overdue_outside' => [1, '/assets/items?overdue=1'],
        'renewal_overdue' => [1, '/assets/accounts?renewing=1'],
        'on_the_way' => [1, '/assets/movements?open=1'],
        'maintenance_due' => [1, '/assets/maintenance'],
        'open_jobs' => [1, '/assets/maintenance'],
        'gaps' => [1, '/assets/counts'],
        'renewing_soon' => [1, '/assets/accounts?renewing=1'],
        'cover_ending' => [3, '/assets/maintenance'],
    ])->and($needs['overdue_outside']['tone'])->toBe('danger')
        ->and($needs['on_the_way']['tone'])->toBe('warning');

    // Without the hub, the two account rows are simply absent.
    $keeper = collect(($this->props)($this->keeper)['needs'])->pluck('key')->all();
    expect($keeper)->toBe(['overdue_outside', 'on_the_way', 'maintenance_due', 'open_jobs', 'gaps', 'cover_ending']);
});

it('says where it all is, by person, place and category, with the idle row last', function () {
    overviewThings();
    $where = ($this->props)($this->keeper)['things']['where'];

    expect($where['people'])->toBe([
        ['label' => 'Rakib Test', 'count' => 1, 'url' => '/assets/items', 'muted' => false],
        ['label' => 'Nobody, in store', 'count' => 2, 'url' => '/assets/items?idle=1', 'muted' => true],
    ])->and($where['places'])->toBe([
        ['label' => 'Corporate Office - Adabor 2', 'count' => 4, 'url' => '/assets/items?place=office%3A'.$this->office->id, 'muted' => false],
        ['label' => 'Savar Warehouse', 'count' => 1, 'url' => '/assets/items?place=warehouse%3A'.$this->warehouse->id, 'muted' => false],
    ])->and(collect($where['categories'])->pluck('count', 'label')->all())->toBe([
        'Computers and IT' => 1, 'Printers and coders' => 1, 'Camera and studio' => 1, 'Small tools and stationery' => 1, 'Furniture' => 1,
    ])->and($where['categories'][0]['url'])->toBe('/assets/items?category='.AssetCategory::query()->where('code', 'IT')->sole()->id);
});

it('shows what is out with outside parties, maintenance, and the stock-takes', function () {
    $items = overviewThings();
    $things = ($this->props)($this->keeper)['things'];

    expect($things['outside']['total'])->toBe(1)
        ->and($things['outside']['rows'])->toBe([[
            'id' => $items['printer']->id, 'tag' => 'BDG-PRN-0001', 'name' => 'LaserJet', 'with' => 'Rahim (Rahim Computer Care)',
            'expected_back_on' => '2026-10-10', 'days_late' => 5, 'url' => '/assets/items/'.$items['printer']->id,
        ]]);

    expect($things['maintenance']['due'])->toBe(1)
        ->and($things['maintenance']['open'])->toBe(1)
        ->and($things['maintenance']['ending'])->toBe(3)
        ->and(collect($things['maintenance']['lines'])->map(fn (array $line) => [$line['on'], $line['text'], $line['days']])->all())->toBe([
            ['2026-10-12', 'ThinkPad, Clean the fan', -3],
            ['2026-10-20', 'Desk chair, warranty ends', 5],
            ['2026-10-30', 'ThinkPad, warranty ends', 15],
            ['2026-11-01', 'Desk chair, service contract ends', 17],
        ]);

    expect($things['counts']['gaps'])->toBe(1)
        ->and($things['counts']['open'])->toBeNull()
        ->and($things['counts']['last_closed'])->toMatchArray(['number' => 'AC-000001', 'place' => 'Corporate Office - Adabor 2', 'closed_on' => '2026-10-02', 'gaps' => 1]);
});

// ── The accounts ────────────────────────────────────────────────────────────

it('counts the accounts on record, the renewals, and what the group shares with us', function () {
    overviewAccounts();
    $accounts = ($this->props)($this->custodian)['accounts'];

    expect($accounts['on_record'])->toBe(4)
        ->and($accounts['renewing_soon'])->toBe(1)
        ->and($accounts['overdue'])->toBe(1)
        ->and($accounts['shared'])->toBe(1)
        ->and(collect($accounts['by_kind'])->pluck('count', 'label')->all())->toBe(['Email' => 1, 'Domain' => 1, 'Hosting' => 1, 'Software (SaaS)' => 1])
        ->and(collect($accounts['soon'])->map(fn (array $row) => [$row['name'], $row['renews_on'], $row['tone']])->all())->toBe([
            ['Shop hosting', '2026-10-10', 'overdue'],
            ['Main domain', '2026-11-01', 'soon'],
        ]);
});

// ── The books ───────────────────────────────────────────────────────────────

it('sums the books the way the fixed assets register does, and never re-derives them', function () {
    overviewBooks();
    $books = ($this->props)($this->manager)['books'];

    expect($books)->toBe([
        'cost' => '1,250,000.00',
        'written_off' => '60,000.00',
        'book_value' => '1,190,000.00',
        'percent_left' => 95,
        'monthly' => '30,000.00',
        'to_write_down' => 1,
        'last_run' => 'September 2026',
        'assets' => 2,
    ]);
});

it('renders an empty business without a number out of place', function () {
    $props = ($this->props)($this->owner);

    expect($props['things']['count'])->toBe(0)
        ->and($props['things']['where']['people'])->toBe([])
        ->and($props['things']['counts']['last_closed'])->toBeNull()
        ->and($props['needs'])->toBe([])
        ->and($props['accounts']['on_record'])->toBe(0)
        ->and($props['books']['last_run'])->toBeNull()
        ->and($props['books']['percent_left'])->toBe(0);
});
