<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessHealthSignals;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Business health, rebuilt 24 Oct 2026 (Joy: "only the necessary part, well
 * designed"): ONE page of today's action signals. Customers overdue, supplier
 * bills due within 7 days, low stock, batches expiring within 30 days, money
 * accounts at zero or below, approvals waiting, stock reserved and not handed
 * over. Each is a count, an amount where it applies, and a link to its list;
 * each is shown only to somebody who may open that list; and the page asks
 * the database a FIXED number of questions however much data there is.
 */
const HEALTH_KEYS = ['customers_overdue', 'bills_due', 'low_stock', 'batches_expiring', 'money_low', 'approvals_waiting', 'stock_reserved'];

const HEALTH_PERMISSIONS = ['accounting.view', 'purchasing.reports.view', 'inventory.reports.view', 'hrm.employees.manage', 'accounting.owner_withdrawal.view', 'inventory.stock.approve'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 09:00:00');

    $this->business = Business::create(['name' => 'Health Co', 'slug' => 'health-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (HEALTH_PERMISSIONS as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(HEALTH_PERMISSIONS);
    Role::firstOrCreate(['name' => 'Storekeeper', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.reports.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahman Traders', 'code' => 'RAHMAN']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->unit = DB::table('units_of_measure')->insertGetId(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc', 'created_at' => now(), 'updated_at' => now()]);

    $this->invoice = function (string $total, string $date, ?string $due, ?Customer $customer = null) {
        static $n = 0;
        $n++;

        return SalesInvoice::create([
            'business_id' => $this->business->id, 'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_number' => 'INV-H-'.$n, 'invoice_date' => $date, 'due_date' => $due,
            'subtotal' => $total, 'total' => $total, 'created_by' => $this->owner->id,
        ]);
    };

    $this->bill = function (string $total, string $date, ?string $due) {
        static $n = 0;
        $n++;

        return SupplierBill::create([
            'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id,
            'bill_number' => 'SB-H-'.$n, 'bill_date' => $date, 'due_date' => $due,
            'total' => $total, 'created_by' => $this->owner->id,
        ]);
    };

    $this->product = function (string $sku, ?string $threshold) {
        return DB::table('products')->insertGetId([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $this->unit, 'sku' => $sku, 'name' => $sku,
            'type' => 'finished_good', 'is_active' => true, 'low_stock_threshold' => $threshold,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->batch = function (int $productId, string $quantity, string $cost, ?string $expiry = null) {
        return DB::table('stock_batches')->insertGetId([
            'business_id' => $this->business->id, 'product_id' => $productId, 'warehouse_id' => $this->warehouse->id,
            'unit_cost' => $cost, 'initial_quantity' => $quantity, 'current_quantity' => $quantity, 'status' => 'active',
            'expiry_date' => $expiry, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->money = function (string $name, string $type, ?int $balanceMinor) {
        static $code = 11500;
        $code++;
        $head = Account::create([
            'code' => (string) $code, 'name' => $name, 'type' => AccountType::Asset,
            'parent_id' => Account::bySlug('cash_at_bank')->parent_id, 'is_postable' => true, 'is_active' => true,
        ]);
        $account = MoneyAccount::create(['business_id' => $this->business->id, 'name' => $name, 'type' => $type, 'account_id' => $head->id]);

        if ($balanceMinor !== null) {
            $journal = DB::table('journals')->insertGetId([
                'business_id' => $this->business->id, 'journal_number' => 'JV-'.$name, 'journal_type' => 'manual',
                'journal_date' => '2026-10-01', 'memo' => 'Opening', 'posted_by' => $this->owner->id, 'posted_at' => now(), 'created_at' => now(),
            ]);
            DB::table('journal_lines')->insert([
                'journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 1,
                'account_id' => $head->id, 'amount_minor' => $balanceMinor, 'created_at' => now(),
            ]);
        }

        return $account;
    };

    $this->signals = fn (User $user) => collect(BusinessHealthSignals::for($user->fresh()))->keyBy('key');
});

afterEach(fn () => Carbon::setTestNow());

it('shows the seven signals and nothing else, all quiet on an empty business', function () {
    ($this->as)($this->owner)->get(route('health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('health')
            ->where('signals', fn ($signals) => collect($signals)->pluck('key')->all() === HEALTH_KEYS
                && collect($signals)->every(fn ($signal) => $signal['count'] === 0)));
});

it('counts every signal with its amount and links each to its list', function () {
    // Customers: two overdue invoices of one customer (one with no due date,
    // so its invoice date), one not yet due, and another customer's.
    ($this->invoice)('1000', '2026-10-01', '2026-10-10');
    ($this->invoice)('500', '2026-10-20', null);
    ($this->invoice)('9000', '2026-10-20', '2026-11-20');
    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Store', 'code' => 'KARIM']);
    ($this->invoice)('250', '2026-09-01', '2026-09-30', $other);

    // Bills: one late, one due in 3 days, one due in 20 days (outside the week).
    ($this->bill)('4000', '2026-10-01', '2026-10-20');
    ($this->bill)('6000', '2026-10-15', '2026-10-27');
    ($this->bill)('7000', '2026-10-15', '2026-11-13');

    // Stock: one low, one out, one fine, one without a line.
    $low = ($this->product)('LOW', '10');
    ($this->batch)($low, '4', '100');
    ($this->product)('OUT', '5');
    $fine = ($this->product)('FINE', '5');
    ($this->batch)($fine, '50', '10');

    // Batches: one expired, one expiring in 10 days, one in 60 days.
    ($this->batch)($fine, '2', '10', '2026-10-20');
    ($this->batch)($fine, '3', '20', '2026-11-03');
    ($this->batch)($fine, '9', '10', '2026-12-23');

    // Money: a bank with nothing, a cash box overdrawn, a cash box with money.
    ($this->money)('Bank', 'bank', null);
    ($this->money)('Petty', 'cash', -150000);
    ($this->money)('Till', 'cash', 500000);

    // A live reservation for one staff purchase, and a released one.
    $batch = ($this->batch)($fine, '10', '30');
    foreach ([[11, null], [12, now()]] as [$reference, $released]) {
        DB::table('stock_reservations')->insert([
            'business_id' => $this->business->id, 'stock_batch_id' => $batch, 'quantity' => '2', 'reason' => 'staff_purchase',
            'reference_type' => (new StaffPurchaseRequest)->getMorphClass(), 'reference_id' => $reference,
            'released_at' => $released, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $signals = ($this->signals)($this->owner);

    expect($signals['customers_overdue'])->toMatchArray(['count' => 2, 'amount' => '1750.00', 'url' => '/accounting/receivables-ageing', 'params' => ['invoices' => 3]])
        ->and($signals['bills_due'])->toMatchArray(['count' => 2, 'amount' => '10000.00', 'url' => '/purchasing/bills-due?days=7', 'params' => ['late' => 1]])
        ->and($signals['low_stock'])->toMatchArray(['count' => 2, 'amount' => null, 'url' => '/inventory/low-stocks', 'params' => ['out' => 1]])
        ->and($signals['batches_expiring'])->toMatchArray(['count' => 2, 'amount' => '80.00', 'url' => '/inventory/expiry?within=30', 'params' => ['expired' => 1]])
        ->and($signals['money_low'])->toMatchArray(['count' => 2, 'amount' => '-1500.00', 'url' => '/accounting/cash-and-bank'])
        ->and($signals['approvals_waiting'])->toMatchArray(['count' => 0, 'url' => '/approvals'])
        ->and($signals['stock_reserved'])->toMatchArray(['count' => 1, 'amount' => '60.00', 'url' => '/hrm/staff-purchases']);

    // Every link is a page this owner may open.
    foreach ($signals as $signal) {
        ($this->as)($this->owner)->get($signal['url'])->assertOk();
    }
});

it('does not let quarantine stock lift a product above its low-stock line', function () {
    $quarantine = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Damaged', 'code' => 'DMG', 'not_for_sale' => true]);
    $product = ($this->product)('JAR', '10');
    ($this->batch)($product, '4', '100');
    DB::table('stock_batches')->insert([
        'business_id' => $this->business->id, 'product_id' => $product, 'warehouse_id' => $quarantine->id,
        'unit_cost' => '100', 'initial_quantity' => '50', 'current_quantity' => '50', 'status' => 'active',
        'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(($this->signals)($this->owner)['low_stock'])->toMatchArray(['count' => 1, 'params' => ['out' => 0]]);

    ($this->as)($this->owner)->get('/inventory/low-stocks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('low', 1)->where('low.0.total', 4));
});

it('counts a confirmed order hold, valued at the average cost on hand', function () {
    Permission::findOrCreate('sales.orders.see', 'web');
    $this->owner->roles()->first()->givePermissionTo('sales.orders.see');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $jar = ($this->product)('JAR', null);
    ($this->batch)($jar, '4', '100');
    ($this->batch)($jar, '4', '200');
    DB::table('stock_reservations')->insert([
        'business_id' => $this->business->id, 'stock_batch_id' => null, 'product_id' => $jar, 'warehouse_id' => $this->warehouse->id,
        'quantity' => '2', 'reason' => 'sales_order', 'reference_type' => (new SalesOrder)->getMorphClass(), 'reference_id' => 77,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(($this->signals)($this->owner)['stock_reserved'])->toMatchArray(['count' => 1, 'amount' => '300.00', 'url' => '/sales/reserved-stock']);
});

it('does not count a paid invoice as overdue', function () {
    $invoice = ($this->invoice)('1000', '2026-10-01', '2026-10-10');
    $receipt = CustomerReceipt::create([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
        'money_account_id' => ($this->money)('Bank', 'bank', null)->id, 'received_on' => '2026-10-12',
        'amount' => '1000', 'created_by' => $this->owner->id,
    ]);
    CustomerReceiptAllocation::create([
        'business_id' => $this->business->id, 'customer_receipt_id' => $receipt->id, 'sales_invoice_id' => $invoice->id, 'amount' => '1000',
    ]);

    expect(($this->signals)($this->owner)['customers_overdue']['count'])->toBe(0);
});

it('shows each person only the signals whose list they may open', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->businesses()->attach($this->business->id);
    $storekeeper->assignRole('Storekeeper');

    // No approver rights and nothing waiting on him: no Approvals card either.
    expect(($this->signals)($storekeeper)->keys()->all())->toBe(['low_stock', 'batches_expiring']);

    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    expect(($this->signals)($nobody)->keys()->all())->toBe([]);

    ($this->as)($nobody)->get(route('health'))->assertOk();
});

it('asks the database the same number of questions however much data there is', function () {
    $queries = function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $this->owner->fresh();
        $user->getAllPermissions();

        DB::flushQueryLog();
        DB::enableQueryLog();
        BusinessHealthSignals::for($user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $add = function (int $i) {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'C'.$i, 'code' => 'C'.$i]);
        ($this->invoice)('100', '2026-10-01', '2026-10-05', $customer);
        ($this->bill)('100', '2026-10-01', '2026-10-25');
        $product = ($this->product)('P'.$i, '10');
        ($this->batch)($product, '1', '5', '2026-11-01');
        ($this->money)('M'.$i, 'cash', null);
    };

    $add(1);
    $few = $queries();

    foreach (range(2, 12) as $i) {
        $add($i);
    }
    $many = $queries();

    expect($many)->toBe($few)
        // ⭐ Integration (25 Oct 2026): + AvailableToSell's three for the low
        // stock line (IP13), still fixed however much data there is.
        ->and($few)->toBeLessThanOrEqual(15);
});

it('drops the signals of the features Joy hid', function () {
    $keys = ($this->signals)($this->owner)->keys()->all();

    expect($keys)->not->toContain('tickets_overdue')
        ->and($keys)->not->toContain('cash_negative_week');
});
