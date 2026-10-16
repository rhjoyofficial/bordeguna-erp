<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Modules\Sales\Models\Customer;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\AttentionSource;
use App\Support\Attention\NeedsAttention;
use App\Support\Attention\Sources\BankUnmatchedSource;
use App\Support\Attention\Sources\StuckParcelsSource;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Needs attention (plan C.4): each source behind its own permission, one
 * ranked list (severity, then oldest), the age computed from when it started,
 * a broken source never breaking the page, the dashboard widget and the badge.
 */
const NA_GRANTS = [
    'Owner' => [
        'dashboard.view_all', 'accounting.view', 'sales.reports.view', 'couriers.parcels.view',
        'couriers.ratecards.propose', 'inventory.reports.view', 'system.jobs.manage',
    ],
    'Finance' => ['accounting.view'],
    'Manager' => ['sales.reports.view', 'inventory.reports.view'],
    'Staff' => ['sales.sell'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Attention Co', 'slug' => 'attention-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (NA_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::create(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }
    (new DashboardAssignmentSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    $this->owner = naMember($this->business, 'Owner');
    $receivable = Account::bySlug('courier_receivable')->id;
    $this->cb = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier, 'account_id' => $receivable, 'is_active' => true]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
});

afterEach(fn () => Carbon::setTestNow());

function naMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function naDelivery(object $t): int
{
    return DB::table('deliveries')->insertGetId([
        'business_id' => $t->business->id, 'customer_id' => $t->customer->id, 'warehouse_id' => $t->warehouse->id,
        'delivery_number' => 'DC-'.Str::random(6), 'delivered_at' => now(), 'created_by' => $t->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function naParcel(object $t, array $attributes): int
{
    return DB::table('consignments')->insertGetId($attributes + [
        'business_id' => $t->business->id, 'delivery_id' => naDelivery($t), 'money_account_id' => $t->cb->id,
        'consignment_number' => 'C-'.Str::random(8), 'cod_amount' => 0, 'status' => 'booked',
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function naBankLine(object $t, string $date): void
{
    $import = DB::table('bank_statement_imports')->insertGetId([
        'business_id' => $t->business->id, 'money_account_id' => $t->cb->id, 'filename' => 'bank.csv',
        'imported_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('bank_statement_lines')->insert([
        'business_id' => $t->business->id, 'import_id' => $import, 'money_account_id' => $t->cb->id, 'txn_date' => $date,
        'description' => 'Deposit', 'amount' => 1500, 'match_status' => 'unmatched',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function naCourierAccount(object $t): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee', 'label' => 'CB main', 'environment' => 'live', 'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ]);
    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()->subDays(3)])->save();

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $t->business->id, 'provider_store_id' => '1',
        'provider_store_name' => 'Adabor', 'money_account_id' => $t->cb->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

function naApiCall(CourierAccount $account, bool $ok, Carbon $at, ?string $error = null): void
{
    $call = new CourierApiCall(['courier_account_id' => $account->id, 'method' => 'GET', 'endpoint' => '/status', 'ok' => $ok, 'error' => $error]);
    $call->created_at = $at;
    $call->save();
}

/** @return array{items: list<AttentionItem>, failed: list<array>} */
function naFor(object $t, User $user): array
{
    return app(NeedsAttention::class)->for($user, $t->business->id);
}

function naKeys(array $items): array
{
    return array_map(fn (AttentionItem $item) => $item->source, $items);
}

function naDictionary(): string
{
    return implode("\n", array_map('file_get_contents', [resource_path('js/lib/i18n.ts'), ...glob(resource_path('js/lib/i18n/*.ts'))]));
}

it('shows each source only to people whose permissions reach it', function () {
    $sources = fn (User $user) => array_map(fn (AttentionSource $s) => $s->key(), app(NeedsAttention::class)->sourcesFor($user));

    $finance = naMember($this->business, 'Finance');
    $manager = naMember($this->business, 'Manager');
    $staff = naMember($this->business, 'Staff');

    expect($sources($this->owner))->toContain('failed_jobs', 'courier_parcels_stuck', 'bank_unmatched', 'stock', 'courier_fee_variance', 'approvals_waiting')
        // The books, never the parcels list or the system.
        ->and($sources($finance))->toEqualCanonicalizing(['courier_settlement_overdue', 'bank_unmatched', 'receivables_overdue', 'payables_due', 'approvals_waiting'])
        ->and($sources($manager))->toContain('courier_parcels_stuck', 'courier_statement_problems', 'stock')
        ->and($sources($manager))->not->toContain('failed_jobs', 'bank_unmatched', 'courier_fee_variance')
        // Only their own approvals.
        ->and($sources($staff))->toBe(['approvals_waiting']);
});

it('ranks by severity, then the oldest first, and computes each age from when it started', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'courier_settlement_alert_days' => 5]);

    naParcel($this, ['courier_status' => 'picked_up', 'courier_status_at' => now()->subDays(4)]);
    naParcel($this, ['courier_status' => 'in_transit', 'courier_status_at' => now()->subDays(9)]);
    naParcel($this, ['status' => 'delivered', 'cod_amount' => 500, 'collected_amount' => 500, 'outcome_on' => today()->subDays(6)->toDateString()]);
    naParcel($this, ['status' => 'delivered', 'cod_amount' => 300, 'collected_amount' => 300, 'outcome_on' => today()->subDays(2)->toDateString()]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncParcels']), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()->subHour()]);

    $items = naFor($this, $this->owner)['items'];
    $summary = array_map(fn (AttentionItem $i) => [$i->source, $i->severity, $i->ageDays()], $items);

    expect($summary)->toBe([
        ['failed_jobs', 'critical', 0],
        ['courier_settlement_overdue', 'high', 6],     // one courier line: 500, the 2-day 300 is not late yet
        ['courier_parcels_stuck', 'normal', 9],         // oldest first
        ['courier_parcels_stuck', 'normal', 4],
    ])
        ->and($items[1]->params)->toMatchArray(['count' => 1, 'courier' => 'CarryBee', 'days' => 5, 'amount' => '500.00'])
        ->and($items[1]->ownerRole)->toBe('Finance')
        ->and($items[0]->actions)->toHaveCount(2);

    // Past twice the policy days it is critical.
    Carbon::setTestNow(now()->addDays(6));
    expect(collect(naFor($this, $this->owner)['items'])->firstWhere('source', 'courier_settlement_overdue')->severity)->toBe('critical');
});

it('says every reason in Bangla too, with the same placeholders', function () {
    naParcel($this, ['courier_status' => 'on_hold', 'courier_status_at' => now()->subDay()]);
    naBankLine($this, today()->subDays(10)->toDateString());
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now()]);

    $dictionary = naDictionary();
    $items = naFor($this, $this->owner)['items'];

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($dictionary)->toContain("'{$item->reasonKey}'");
    }

    // Every reason key the sources can produce, read off the source files.
    preg_match_all('/\$this->item\(\s*[^,]+,\s*[^,]+,\s*(?:[^,\']*\?\s*)?\'([a-z_]+)\'(?: : \'([a-z_]+)\')?/s', implode("\n", array_map('file_get_contents', glob(app_path('Support/Attention/Sources/*.php')))), $keys);
    $declared = array_filter(array_merge($keys[1], $keys[2]));

    expect($declared)->not->toBeEmpty();
    foreach ($declared as $key) {
        expect($dictionary)->toContain("'attention.reason.{$key}'");
    }
});

it('keeps the page and the rest of the list when one source fails', function () {
    $broken = new class implements AttentionSource
    {
        public function key(): string
        {
            return 'broken';
        }

        public function area(): string
        {
            return 'money';
        }

        public function authorize(User $user): bool
        {
            return true;
        }

        public function items(User $user, int $businessId): array
        {
            throw new RuntimeException('database went away');
        }
    };

    app()->instance(NeedsAttention::class, new NeedsAttention([$broken, app(StuckParcelsSource::class), app(BankUnmatchedSource::class)]));
    naParcel($this, ['courier_status' => 'on_hold', 'courier_status_at' => now()->subDay()]);

    $result = naFor($this, $this->owner);
    expect(naKeys($result['items']))->toBe(['courier_parcels_stuck'])
        ->and($result['failed'])->toBe([['key' => 'broken', 'area' => 'money']]);

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get('/attention')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('attention')
            ->has('items', 1)
            ->where('failed.0.key', 'broken'));
});

it('filters by severity, area and what is mine, never past what the person may see', function () {
    naParcel($this, ['courier_status' => 'lost', 'courier_status_at' => now()->subDay()]);       // high, Manager
    naParcel($this, ['courier_status' => 'picked_up', 'courier_status_at' => now()->subDays(5)]); // normal, Manager
    naBankLine($this, today()->toDateString());                                                     // normal, Finance

    $visit = fn (User $user, string $query = '') => $this->actingAs($user->fresh())
        ->withSession(['current_business_id' => $this->business->id])->get('/attention'.$query)->assertOk();

    $visit($this->owner)->assertInertia(fn ($page) => $page->has('items', 3)->where('total', 3)->where('counts.high', 1)->where('counts.normal', 2));
    $visit($this->owner, '?severity=high')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.params.status', 'Lost'));
    $visit($this->owner, '?area=money')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.source', 'bank_unmatched'));
    // The Owner holds neither Manager nor Finance: nothing here is addressed to them.
    $visit($this->owner, '?mine=1')->assertInertia(fn ($page) => $page->has('items', 0));

    $manager = naMember($this->business, 'Manager');
    $visit($manager)->assertInertia(fn ($page) => $page->has('items', 2));
    $visit($manager, '?mine=1')->assertInertia(fn ($page) => $page->has('items', 2)->where('items.0.mine', true));
    // A brand of no business of ours reads as all brands (OrderBrand::filter).
    $visit($manager, '?brand=99')->assertInertia(fn ($page) => $page->has('items', 2)->where('filters.brand', null));

    $staff = naMember($this->business, 'Staff');
    $visit($staff)->assertInertia(fn ($page) => $page->has('items', 0)->where('total', 0));
});

it('gives each parcel its order brand and filters by it, keeping business-wide lines under every brand', function () {
    $lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'SKU-1', 'name' => 'Ghee', 'type' => ProductType::FinishedGood]);

    // A parcel whose challan carries an order line of the given brand.
    $branded = function (?Brand $brand, string $status) use ($product) {
        $order = DB::table('sales_orders')->insertGetId([
            'business_id' => $this->business->id, 'customer_id' => $this->customer->id, 'order_number' => 'SO-'.Str::random(6),
            'ordered_at' => today()->toDateString(), 'created_by' => $this->owner->id, 'brand_id' => $brand?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = DB::table('sales_order_lines')->insertGetId([
            'business_id' => $this->business->id, 'sales_order_id' => $order, 'product_id' => $product->id,
            'quantity_ordered' => 1, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $parcel = naParcel($this, ['courier_status' => $status, 'courier_status_at' => now()->subDay()]);
        DB::table('delivery_lines')->insert([
            'business_id' => $this->business->id, 'delivery_id' => DB::table('consignments')->where('id', $parcel)->value('delivery_id'),
            'product_id' => $product->id, 'quantity' => 1, 'sales_order_line_id' => $line, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $branded($lld, 'on_hold');
    $branded($bnc, 'lost');
    $branded(null, 'delivery_failed');
    naBankLine($this, today()->toDateString());

    $visit = fn (string $query = '') => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])->get('/attention'.$query)->assertOk();

    // Three parcels, the bank line, the new product out of stock, and (round 3)
    // the same product with no selling price, a business-wide line.
    $visit()->assertInertia(fn ($page) => $page->has('items', 6)->has('brands', 2));

    $visit('?brand='.$lld->id)->assertInertia(fn ($page) => $page->has('items', 4)
        ->where('filters.brand', (string) $lld->id)
        ->where('items', fn ($items) => collect($items)->pluck('brand', 'source')->sortKeys()->all() === ['bank_unmatched' => null, 'courier_parcels_stuck' => 'LaLaDia', 'stock' => null, 'unpriced_sellable' => null]));

    $visit('?brand=none')->assertInertia(fn ($page) => $page->has('items', 4)
        ->where('items', fn ($items) => collect($items)->where('source', 'courier_parcels_stuck')->pluck('params.status')->all() === ['Delivery failed']));

    expect(collect(naFor($this, $this->owner)['items'])->where('source', 'courier_parcels_stuck')->pluck('brandId')->sort()->values()->all())
        ->toBe([null, $lld->id, $bnc->id]);
});

it('flags a failing courier API, and a quiet one while parcels are open', function () {
    $account = naCourierAccount($this);
    naParcel($this, ['courier_account_id' => $account->id, 'courier_status' => 'in_transit', 'courier_status_at' => now()->subHour()]);

    naApiCall($account, true, now()->subHours(5));

    $sources = fn () => collect(naFor($this, $this->owner)['items'])->where('source', 'courier_connections')->map(fn ($i) => $i->reasonKey)->values()->all();

    // Nothing read for 5 hours, nothing pushed since it was switched on 3 days ago.
    expect($sources())->toEqualCanonicalizing(['attention.reason.courier_stale', 'attention.reason.courier_webhook_silent']);

    naApiCall($account, false, now()->subMinutes(5), 'HTTP 500');

    // Failing says it once; stale and quiet are not repeated beside it.
    expect($sources())->toBe(['attention.reason.courier_failing']);
});

it('puts the top of the list on the Owner, Finance and Manager dashboards only', function () {
    naParcel($this, ['courier_status' => 'on_hold', 'courier_status_at' => now()->subDay()]);
    $registry = app(WidgetRegistry::class);
    $staff = naMember($this->business, 'Staff');

    expect($registry->forDashboard(DashboardKey::Owner, $this->owner)->keys()->first())->toBe('needs_attention')
        ->and($registry->forDashboard(DashboardKey::Sales, $staff)->has('needs_attention'))->toBeFalse();

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/widgets/needs_attention?dashboard=owner')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.kind', 'attention')
        ->assertJsonPath('data.total_count', 1)
        ->assertJsonPath('data.items.0.source', 'courier_parcels_stuck');
});

it('counts the sidebar badge from the same list', function () {
    naParcel($this, ['courier_status' => 'on_hold', 'courier_status_at' => now()->subDay()]);
    naBankLine($this, today()->toDateString());

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get('/attention')
        ->assertInertia(fn ($page) => $page->where('attentionCount', 2));

    expect(app(NeedsAttention::class)->count($this->owner, $this->business->id))->toBe(2);
});

// Round 3 (16 Oct 2026): the classic dashboard's "no selling price" banner is one row here.
it('lists sellable products with no selling price in force, to whoever reads sales or sets prices', function () {
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    foreach (['Ghee', 'Honey', 'Oil'] as $index => $name) {
        Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => "SKU-U{$index}", 'name' => $name, 'type' => ProductType::FinishedGood]);
    }
    // A raw material is never sold, so it is never counted.
    Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'SKU-RAW', 'name' => 'Jar', 'type' => ProductType::RawMaterial]);
    DB::table('product_prices')->insert([
        'business_id' => $this->business->id, 'product_id' => Product::where('name', 'Ghee')->value('id'),
        'unit_price' => 500, 'effective_from' => today()->subMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = collect(naFor($this, $this->owner)['items'])->firstWhere('source', 'unpriced_sellable');

    expect($item)->not->toBeNull()
        ->and($item->severity)->toBe(AttentionItem::HIGH)
        ->and($item->area)->toBe('stock')
        ->and($item->url)->toBe('/sales/prices')
        ->and($item->params)->toBe(['count' => 2, 'sellable' => 3])
        ->and($item->reasonKey)->toBe('attention.reason.unpriced_sellable')
        ->and(naDictionary())->toContain("'attention.reason.unpriced_sellable'");

    // Finance reads neither sales nor prices: not shown.
    expect(naKeys(naFor($this, naMember($this->business, 'Finance'))['items']))->not->toContain('unpriced_sellable');

    // The classic dashboard's banner reads the same figure.
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get('/dashboard/classic')
        ->assertInertia(fn ($page) => $page->where('unpriced_sellable', ['unpriced' => 2, 'sellable' => 3]));
});
