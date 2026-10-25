<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ B12 (Joy's initial plan, 25 Oct 2026; sales guide gaps S1, S2): the till
 * and the books agree.
 *
 * Cash in / cash out on a shift is a real money transfer, or an expense
 * voucher when the cash was spent on a chosen head, never a note. "Expected"
 * reads them from the ledger once: float + cash sales + cash in - cash out.
 * Only the shift's own cashier, or an Owner or Manager, records a move on it
 * or closes it. And every shift has a Z summary.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Till Co', 'slug' => 'till-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-10-25 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop floor', 'code' => 'SHOP']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '100', '500', User::factory()->create(), occurredAt: '2026-09-01 09:00:00');

    $this->till = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Front Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    $this->bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => Account::bySlug('cash_at_bank')->id]);
    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash', 'type' => 'mfs',
        'account_id' => Account::create(['code' => '11309', 'name' => 'bKash Wallet', 'type' => 'asset', 'is_postable' => true])->id,
    ]);

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->cashier = ($this->userAs)('Staff');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    ($this->as)($this->cashier)->post(route('sales.shifts.open'), ['money_account_id' => $this->till->id, 'opening_float' => 1000, 'float_from' => 'in_till'])->assertSessionHasNoErrors();
    $this->shift = (int) DB::table('pos_shifts')->latest('id')->value('id');

    // A cash sale of four jars (3,400) puts real money in the till's ledger.
    $this->cashSale = fn (string $qty = '4') => ($this->as)($this->cashier)->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'payments' => [['money_account_id' => $this->till->id, 'amount' => (string) (850 * (int) $qty)]],
        'lines' => [['product_id' => $this->honey->id, 'quantity' => $qty]],
    ])->assertSessionHasNoErrors();

    $this->move = fn (User $user, array $data): TestResponse => ($this->as)($user)->post(route('sales.shifts.movement', $this->shift), $data);

    $this->expected = fn () => (float) ($this->as)($this->cashier)->get(route('sales.shifts'))->viewData('page')['props']['open_shifts'][0]['expected_cash'];
    $this->head = fn () => Account::query()->findOrFail(($this->as)($this->cashier)->get(route('sales.shifts'))->viewData('page')['props']['expense_accounts'][0]['id']);
    $this->ledger = fn (int $accountId) => (int) JournalLine::where('account_id', $accountId)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('posts cash spent from the drawer as an expense voucher on the chosen head, counted once in expected', function () {
    ($this->cashSale)();
    $head = ($this->head)();

    ($this->move)($this->cashier, ['type' => 'cash_out', 'kind' => 'expense', 'expense_account_id' => $head->id, 'amount' => '150', 'reason' => 'Staff tea'])
        ->assertSessionHasNoErrors();

    $voucher = ExpenseVoucher::sole();
    $row = DB::table('pos_cash_movements')->sole();

    expect((float) $voucher->total)->toBe(150.0)
        ->and($voucher->money_account_id)->toBe($this->till->id)
        ->and((int) $row->expense_voucher_id)->toBe($voucher->id)
        ->and($row->kind)->toBe('expense')
        ->and(($this->ledger)($head->id))->toBe(15000)
        ->and(($this->ledger)(Account::bySlug('cash_in_hand')->id))->toBe(340000 - 15000)
        // 1,000 + 3,400 - 150, NOT 1,000 + 3,400 - 150 - 150.
        ->and(($this->expected)())->toBe(4250.0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('posts cash in and cash out as money transfers with the other account', function () {
    ($this->cashSale)();

    ($this->move)($this->cashier, ['type' => 'cash_out', 'kind' => 'transfer', 'other_money_account_id' => $this->bank->id, 'amount' => '3000', 'reason' => 'Banked at noon'])
        ->assertSessionHasNoErrors();
    ($this->move)($this->cashier, ['type' => 'cash_in', 'kind' => 'transfer', 'other_money_account_id' => $this->bank->id, 'amount' => '500', 'reason' => 'Change from the bank'])
        ->assertSessionHasNoErrors();

    $transfers = MoneyTransfer::orderBy('id')->get();

    expect($transfers)->toHaveCount(2)
        ->and([$transfers[0]->from_money_account_id, $transfers[0]->to_money_account_id])->toBe([$this->till->id, $this->bank->id])
        ->and([$transfers[1]->from_money_account_id, $transfers[1]->to_money_account_id])->toBe([$this->bank->id, $this->till->id])
        ->and(DB::table('pos_cash_movements')->whereNotNull('money_transfer_id')->count())->toBe(2)
        ->and(($this->ledger)(Account::bySlug('cash_at_bank')->id))->toBe(250000)
        // 1,000 + 3,400 + 500 - 3,000.
        ->and(($this->expected)())->toBe(1900.0);

    // Closing on the count: exactly balanced.
    ($this->as)($this->cashier)->post(route('sales.shifts.close', $this->shift), ['closing_count' => 1900])->assertSessionHasNoErrors();
    expect((float) DB::table('pos_shifts')->find($this->shift)->over_short)->toBe(0.0);
});

it('still counts an old note-only movement, written before movements posted', function () {
    DB::table('pos_cash_movements')->insert([
        'business_id' => $this->business->id, 'shift_id' => $this->shift, 'type' => 'cash_out', 'amount' => '200',
        'reason' => 'Old note', 'recorded_by' => $this->cashier->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(($this->expected)())->toBe(800.0);
});

it('refuses an expense coming in, a transfer with no account, and a transfer into itself; nothing is written', function () {
    ($this->move)($this->cashier, ['type' => 'cash_in', 'kind' => 'expense', 'expense_account_id' => ($this->head)()->id, 'amount' => '100', 'reason' => 'Spent'])
        ->assertSessionHasErrors('kind');

    ($this->move)($this->cashier, ['type' => 'cash_in', 'kind' => 'transfer', 'amount' => '100', 'reason' => 'From somewhere'])
        ->assertSessionHasErrors('other_money_account_id');

    // A transfer into the till from the till itself.
    ($this->move)($this->cashier, ['type' => 'cash_in', 'kind' => 'transfer', 'other_money_account_id' => $this->till->id, 'amount' => '100', 'reason' => 'Itself'])
        ->assertSessionHasErrors('movement');

    expect(DB::table('pos_cash_movements')->count())->toBe(0)
        ->and(MoneyTransfer::count())->toBe(0)
        ->and(ExpenseVoucher::count())->toBe(0)
        ->and((int) JournalLine::count())->toBe(0);
});

it('lets only the shift\'s own cashier, an Owner or a Manager record a move or close it', function () {
    ($this->cashSale)();
    $colleague = ($this->userAs)('Staff');
    $move = ['type' => 'cash_out', 'kind' => 'transfer', 'other_money_account_id' => $this->bank->id, 'amount' => '100', 'reason' => 'Banked'];

    ($this->move)($colleague, $move)->assertSessionHasErrors('shift');
    ($this->as)($colleague)->post(route('sales.shifts.close', $this->shift), ['closing_count' => 0])->assertSessionHasErrors('shift');

    expect(session('errors')->first('shift'))->toContain('Only')
        ->and(DB::table('pos_cash_movements')->count())->toBe(0)
        ->and(DB::table('pos_shifts')->find($this->shift)->status)->toBe('open');

    // The screen says so too.
    ($this->as)($colleague)->get(route('sales.shifts'))
        ->assertInertia(fn (Assert $page) => $page->where('open_shifts.0.may_handle', false));

    ($this->move)(($this->userAs)('Manager'), $move)->assertSessionHasNoErrors();
    ($this->as)(($this->userAs)('Owner'))->post(route('sales.shifts.close', $this->shift), ['closing_count' => 4300])->assertSessionHasNoErrors();

    expect(DB::table('pos_shifts')->find($this->shift)->status)->toBe('closed');
});

it('closes once: a second close changes nothing', function () {
    ($this->as)($this->cashier)->post(route('sales.shifts.close', $this->shift), ['closing_count' => 1000])->assertSessionHasNoErrors();
    $first = DB::table('pos_shifts')->find($this->shift);

    ($this->as)($this->cashier)->post(route('sales.shifts.close', $this->shift), ['closing_count' => 5])->assertNotFound();

    expect((float) DB::table('pos_shifts')->find($this->shift)->closing_count)->toBe((float) $first->closing_count);
});

it('shows the Z summary: sales by payment method, cash in and out, expected against counted', function () {
    ($this->cashSale)('2');   // 1,700 cash

    // One bill split: 1,000 cash + 700 bKash; one on account for a named shop.
    ($this->as)($this->cashier)->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id, 'warehouse_id' => $this->warehouse->id,
        'payments' => [['money_account_id' => $this->till->id, 'amount' => '1000'], ['money_account_id' => $this->bkash->id, 'amount' => '700']],
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();
    $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-01', 'name' => 'Karim Traders', 'type' => CustomerType::Retail, 'credit_limit' => '5000']);
    ($this->as)($this->cashier)->post('/sales/counter', [
        'customer_id' => $shop->id, 'warehouse_id' => $this->warehouse->id, 'payments' => [],
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']],
    ])->assertSessionHasNoErrors();

    ($this->move)($this->cashier, ['type' => 'cash_out', 'kind' => 'expense', 'expense_account_id' => ($this->head)()->id, 'amount' => '150', 'reason' => 'Staff tea'])
        ->assertSessionHasNoErrors();

    // 1,000 + 1,700 + 1,000 - 150 = 3,550 expected; 3,500 counted.
    ($this->as)($this->cashier)->post(route('sales.shifts.close', $this->shift), ['closing_count' => 3500])->assertSessionHasNoErrors();

    ($this->as)($this->cashier)->get(route('sales.shifts.summary', $this->shift))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/shift-summary')
            ->where('sales.count', 3)
            ->where('sales.billed', '4250.00')
            ->where('sales.by_method', [['name' => 'Front Till', 'amount' => '2700.00'], ['name' => 'bKash', 'amount' => '700.00']])
            ->where('sales.on_account', '850.00')
            ->where('cash.moves_out', '150.00')
            ->where('cash.moves_in', '0.00')
            ->where('cash.expected', '3550.00')
            ->where('cash.counted', '3500.00')
            ->where('cash.difference', '-50.00'));
});
