<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
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
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's answer 5 (25 Oct 2026): the opening float is recorded in the books
 * when a shift opens (a money transfer into the till from the account it came
 * from), the counted cash may be handed over at close (a transfer out), and
 * "expected" counts the float exactly once.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Float Co', 'slug' => 'float-co']);
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

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop floor', 'code' => 'SHOP']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($honey, $warehouse, '100', '500', User::factory()->create(), occurredAt: '2026-09-01 09:00:00');

    $this->till = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Front Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    $this->bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => Account::bySlug('cash_at_bank')->id]);

    $cashier = User::factory()->create();
    $cashier->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $cashier->assignRole('Staff');
    $this->as = fn () => $this->actingAs($cashier)->withSession(['current_business_id' => $this->business->id]);

    $this->cashSale = fn () => ($this->as)()->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $warehouse->id,
        'payments' => [['money_account_id' => $this->till->id, 'amount' => '3400']],
        'lines' => [['product_id' => $honey->id, 'quantity' => '4']],
    ])->assertSessionHasNoErrors();

    $this->open = fn (array $extra = []) => ($this->as)()->post(route('sales.shifts.open'), $extra + ['money_account_id' => $this->till->id, 'opening_float' => '1000', 'float_from' => (string) $this->bank->id]);
    $this->shift = fn () => DB::table('pos_shifts')->latest('id')->first();
    $this->expected = fn () => (float) ($this->as)()->get(route('sales.shifts'))->viewData('page')['props']['open_shifts'][0]['expected_cash'];
    $this->ledger = fn (MoneyAccount $account) => (int) JournalLine::where('account_id', $account->account_id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('posts the float from the bank into the till on the shift date and counts it once in expected', function () {
    // The bank may go below zero here (an overdraft), so the float can come out of it.
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    ($this->open)()->assertSessionHasNoErrors();

    $shift = ($this->shift)();
    $transfer = MoneyTransfer::sole();

    expect((int) $shift->float_transfer_id)->toBe($transfer->id)
        ->and($shift->float_journal_id)->not->toBeNull()
        ->and($transfer->from_money_account_id)->toBe($this->bank->id)
        ->and($transfer->to_money_account_id)->toBe($this->till->id)
        ->and($transfer->transferred_on->toDateString())->toBe('2026-10-25')
        ->and((float) $transfer->amount)->toBe(1000.0)
        ->and(($this->ledger)($this->till))->toBe(100000)
        // Float once: not 2,000.
        ->and(($this->expected)())->toBe(1000.0);

    ($this->cashSale)();

    expect(($this->expected)())->toBe(4400.0)
        ->and(($this->ledger)($this->till))->toBe(440000)
        ->and((int) DB::table('journal_lines')->sum('amount_minor'))->toBe(0);
});

it('hands the counted cash over at close, by default where the float came from, and the Z summary names both transfers', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);
    ($this->open)()->assertSessionHasNoErrors();
    ($this->cashSale)();
    $shift = ($this->shift)();

    // The screen offers the account the float came from as the hand-over default.
    ($this->as)()->get(route('sales.shifts'))->assertInertia(fn (Assert $page) => $page->where('open_shifts.0.float_from_id', $this->bank->id)->etc());

    ($this->as)()->post(route('sales.shifts.close', $shift->id), ['closing_count' => '4400', 'hand_over_to' => (string) $this->bank->id])->assertSessionHasNoErrors();

    $closed = ($this->shift)();
    $handOver = MoneyTransfer::query()->whereKey($closed->handover_transfer_id)->sole();

    expect((float) $closed->expected_cash)->toBe(4400.0)
        ->and((float) $closed->over_short)->toBe(0.0)
        ->and($handOver->from_money_account_id)->toBe($this->till->id)
        ->and($handOver->to_money_account_id)->toBe($this->bank->id)
        ->and((float) $handOver->amount)->toBe(4400.0)
        // The till is empty in the books, as it is in the drawer.
        ->and(($this->ledger)($this->till))->toBe(0);

    ($this->as)()->get(route('sales.shifts.summary', $shift->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cash.float_document', MoneyTransfer::query()->whereKey($closed->float_transfer_id)->value('transfer_number'))
            ->where('cash.float_from', 'BRAC Bank')
            ->where('cash.float_not_posted', false)
            ->where('cash.handover_document', $handOver->transfer_number)
            ->where('cash.handover_to', 'BRAC Bank')
            ->where('cash.expected', '4400.00')
            ->etc());
});

it('needs to know where the float came from, and opens nothing when it is not said or cannot be paid', function () {
    ($this->open)(['float_from' => ''])->assertSessionHasErrors('float_from');
    ($this->open)(['float_from' => (string) $this->till->id])->assertSessionHasErrors('float_from');

    // The bank holds nothing and the policy refuses a bank below zero: nothing is written, not even the shift.
    config(['accounting.money_availability' => true]);
    ($this->open)()->assertSessionHasErrors('float_from');

    expect(DB::table('pos_shifts')->count())->toBe(0)
        ->and(MoneyTransfer::count())->toBe(0);
});

it('keeps a float already in the till, or an old shift with no transfer, working and says so on the Z summary', function () {
    ($this->open)(['float_from' => 'in_till'])->assertSessionHasNoErrors();
    $shift = ($this->shift)();

    expect(MoneyTransfer::count())->toBe(0)
        ->and($shift->float_transfer_id)->toBeNull()
        ->and(($this->expected)())->toBe(1000.0);

    ($this->as)()->get(route('sales.shifts.summary', $shift->id))
        ->assertInertia(fn (Assert $page) => $page->where('cash.float_not_posted', true)->where('cash.float_document', null)->etc());

    // Closing without a hand-over leaves the cash in the till.
    ($this->as)()->post(route('sales.shifts.close', $shift->id), ['closing_count' => '1000', 'hand_over_to' => 'in_till'])->assertSessionHasNoErrors();

    expect(($this->shift)()->handover_transfer_id)->toBeNull()
        ->and(MoneyTransfer::count())->toBe(0);
});

it('refuses a second open of the same till (double click) without a second transfer', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'refuse_negative_money' => false]);

    ($this->open)()->assertSessionHasNoErrors();
    ($this->open)()->assertSessionHasErrors('money_account_id');

    expect(DB::table('pos_shifts')->count())->toBe(1)
        ->and(MoneyTransfer::count())->toBe(1);
});
