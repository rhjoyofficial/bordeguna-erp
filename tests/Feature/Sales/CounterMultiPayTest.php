<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ B12 (Joy's initial plan R107 and R110, 25 Oct 2026): one counter bill,
 * several payment lines.
 *
 * Cash into the cashier's own till, bKash, card into the bank, all on one
 * bill; whatever they leave unpaid stays on account, but only for a customer
 * the credit rules allow (a walk-in pays in full). Each line is its own
 * receipt with its own Dr money / Cr Accounts Receivable, against the one
 * invoice. Cash given and the change are kept on the bill, cash line only.
 * And the sale date: today, unless an Owner or Manager picks another open day.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Counter Co', 'slug' => 'counter-co']);
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

    Carbon::setTestNow('2026-10-25 11:00:00');

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
    $this->bkashGl = Account::create(['code' => '11309', 'name' => 'bKash Wallet', 'type' => 'asset', 'is_postable' => true]);
    $this->bkash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'bKash', 'type' => 'mfs', 'account_id' => $this->bkashGl->id]);

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->cashier = ($this->userAs)('Staff');
    DB::table('pos_shifts')->insert([
        'business_id' => $this->business->id, 'money_account_id' => $this->till->id, 'opened_by' => $this->cashier->id,
        'opened_at' => now()->subHour(), 'opening_float' => '1000.00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Two jars = 1,700, unless the case says otherwise.
    $this->sell = function (User $user, array $payments, array $overrides = []): TestResponse {
        return $this->actingAs($user)->post('/sales/counter', array_merge([
            'customer_id' => Customer::walkIn()->id,
            'warehouse_id' => $this->warehouse->id,
            'payments' => $payments,
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '2']],
        ], $overrides));
    };

    $this->ledger = fn (int $accountId) => (int) JournalLine::where('account_id', $accountId)->sum('amount_minor');
    $this->onHand = fn () => (float) StockBatch::where('product_id', $this->honey->id)->sum('current_quantity');
});

afterEach(fn () => Carbon::setTestNow());

it('takes one bill in cash, bKash and card: one receipt per account, each with its own debit, AR back to zero', function () {
    ($this->sell)($this->cashier, [
        ['money_account_id' => $this->till->id, 'amount' => '700'],
        ['money_account_id' => $this->bkash->id, 'amount' => '600'],
        ['money_account_id' => $this->bank->id, 'amount' => '400'],
    ], ['cash_given' => '1000'])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::sole();
    $receipts = CustomerReceipt::orderBy('id')->get();

    expect($receipts->pluck('money_account_id')->all())->toBe([$this->till->id, $this->bkash->id, $this->bank->id])
        ->and($receipts->map(fn ($r) => (float) $r->amount)->all())->toBe([700.0, 600.0, 400.0])
        ->and($invoice->outstanding()->toTaka())->toBe('0.00')
        ->and(($this->ledger)(Account::bySlug('cash_in_hand')->id))->toBe(70000)
        ->and(($this->ledger)($this->bkashGl->id))->toBe(60000)
        ->and(($this->ledger)(Account::bySlug('cash_at_bank')->id))->toBe(40000)
        ->and(($this->ledger)(Account::bySlug('accounts_receivable')->id))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0)
        // Cash given and change, on the cash line only: 1,000 - 700.
        ->and((float) $invoice->cash_given)->toBe(1000.0)
        ->and((float) $invoice->change_given)->toBe(300.0);

    $sale = session('counterSale');
    expect($sale['on_account'])->toBeFalse()
        ->and($sale['change'])->toBe('300.00')
        ->and(collect($sale['payments'])->pluck('name')->all())->toBe(['Front Till', 'bKash', 'BRAC Bank']);
});

it('prints every payment line, cash given and change on the slip', function () {
    ($this->sell)($this->cashier, [
        ['money_account_id' => $this->till->id, 'amount' => '1000'],
        ['money_account_id' => $this->bkash->id, 'amount' => '700'],
    ], ['cash_given' => '1000'])->assertSessionHasNoErrors();

    $payments = $this->actingAs($this->cashier)->get(route('sales.invoices.slip', SalesInvoice::sole()))
        ->assertOk()->viewData('page')['props']['document']['payments'];

    expect(collect($payments)->pluck('label')->all())->toBe(['Paid (Front Till)', 'Paid (bKash)', 'Cash given', 'Change']);
});

it('leaves the unpaid rest on account for a named customer within their credit limit', function () {
    $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-01', 'name' => 'Karim Traders', 'type' => CustomerType::Retail, 'credit_limit' => '500']);

    ($this->sell)($this->cashier, [['money_account_id' => $this->bkash->id, 'amount' => '1200']], ['customer_id' => $shop->id])
        ->assertSessionHasNoErrors();

    expect(SalesInvoice::sole()->outstanding()->toTaka())->toBe('500.00')
        ->and(($this->ledger)(Account::bySlug('accounts_receivable')->id))->toBe(50000)
        ->and(session('counterSale')['left_on_account'])->toBe('500.00');
});

it('asks the credit rules about the REST only, and takes the whole sale back when refused', function () {
    $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-02', 'name' => 'Karim Traders', 'type' => CustomerType::Retail, 'credit_limit' => '500']);

    ($this->sell)($this->cashier, [['money_account_id' => $this->bkash->id, 'amount' => '1000']], ['customer_id' => $shop->id])
        ->assertSessionHasErrors('payments');

    expect(session('errors')->first('payments'))->toContain('would owe 700.00 with this sale, over their credit limit of 500.00')
        ->and(SalesInvoice::count())->toBe(0)
        ->and(CustomerReceipt::count())->toBe(0)
        ->and(($this->onHand)())->toBe(100.0);
});

it('refuses a distributor any unpaid rest', function () {
    $distributor = Customer::create(['business_id' => $this->business->id, 'code' => 'D-01', 'name' => 'Ctg Distribution', 'type' => CustomerType::Distributor, 'credit_limit' => '1000000']);

    ($this->sell)(($this->userAs)('Owner'), [['money_account_id' => $this->bank->id, 'amount' => '1000']], ['customer_id' => $distributor->id])
        ->assertSessionHasErrors('payments');

    expect(session('errors')->first('payments'))->toContain('distributors pay in advance')
        ->and(SalesInvoice::count())->toBe(0);
});

it('makes a walk-in pay the whole bill: nothing taken, or only part, is refused', function () {
    ($this->sell)($this->cashier, [])->assertSessionHasErrors('payments');
    expect(session('errors')->first('payments'))->toContain('walk-in customer cannot take goods on account');

    ($this->sell)($this->cashier, [['money_account_id' => $this->bkash->id, 'amount' => '1000']])
        ->assertSessionHasErrors('payments');
    expect(session('errors')->first('payments'))->toContain('700.00 still to pay')
        ->and(SalesInvoice::count())->toBe(0)
        ->and(CustomerReceipt::count())->toBe(0)
        ->and(($this->onHand)())->toBe(100.0);
});

it('refuses payments that add up to more than the bill', function () {
    ($this->sell)($this->cashier, [
        ['money_account_id' => $this->till->id, 'amount' => '1000'],
        ['money_account_id' => $this->bkash->id, 'amount' => '1000'],
    ])->assertSessionHasErrors('payments');

    expect(session('errors')->first('payments'))->toContain('more than the bill of 1,700.00')
        ->and(SalesInvoice::count())->toBe(0);
});

it('refuses the same account twice on one bill', function () {
    ($this->sell)($this->cashier, [
        ['money_account_id' => $this->bkash->id, 'amount' => '1000'],
        ['money_account_id' => $this->bkash->id, 'amount' => '700'],
    ])->assertSessionHasErrors('payments.1.money_account_id');

    expect(SalesInvoice::count())->toBe(0);
});

it('keeps a cash line in the cashier\'s own till, and refuses less cash handed over than the cash line', function () {
    $other = ($this->userAs)('Staff');

    // No shift of their own: cash is refused, bKash would still work.
    ($this->sell)($other, [['money_account_id' => $this->till->id, 'amount' => '1700']])
        ->assertSessionHasErrors('payments.0.money_account_id');
    expect(session('errors')->first('payments.0.money_account_id'))->toContain('Open your shift first');

    ($this->sell)($this->cashier, [
        ['money_account_id' => $this->till->id, 'amount' => '1000'],
        ['money_account_id' => $this->bkash->id, 'amount' => '700'],
    ], ['cash_given' => '900'])->assertSessionHasErrors('cash_given');

    expect(session('errors')->first('cash_given'))->toContain('less than the 1,000.00 taken in cash')
        ->and(SalesInvoice::count())->toBe(0);
});

it('leaves nothing behind when a second post is refused: never a half sale', function () {
    // The counter has no idempotency key; what the server guarantees is that
    // every post is all-or-nothing. A second post that fails leaves nothing.
    ($this->sell)($this->cashier, [['money_account_id' => $this->bank->id, 'amount' => '1700']])->assertSessionHasNoErrors();
    ($this->sell)($this->cashier, [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['lines' => [['product_id' => $this->honey->id, 'quantity' => '999']]])
        ->assertSessionHasErrors('lines');

    expect(SalesInvoice::count())->toBe(1)
        ->and(CustomerReceipt::count())->toBe(1)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

describe('the sale date (R110)', function () {
    it('lets a Manager date the sale on an earlier open day; every document carries it', function () {
        ($this->sell)(($this->userAs)('Manager'), [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['sold_on' => '2026-10-20'])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::sole();

        expect($invoice->invoice_date->toDateString())->toBe('2026-10-20')
            ->and(CustomerReceipt::sole()->received_on->toDateString())->toBe('2026-10-20');
    });

    it('keeps Staff on today', function () {
        ($this->sell)($this->cashier, [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['sold_on' => '2026-10-20'])
            ->assertSessionHasErrors('sold_on');

        // Today, sent explicitly, is simply today.
        ($this->sell)($this->cashier, [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['sold_on' => '2026-10-25'])
            ->assertSessionHasNoErrors();
    });

    it('refuses the future and a closed month, even for the Owner', function () {
        $owner = ($this->userAs)('Owner');

        ($this->sell)($owner, [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['sold_on' => '2026-10-26'])
            ->assertSessionHasErrors('sold_on');

        AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $owner);

        ($this->sell)($owner, [['money_account_id' => $this->bank->id, 'amount' => '1700']], ['sold_on' => '2026-09-28'])
            ->assertSessionHasErrors('sold_on');

        expect(session('errors')->first('sold_on'))->toContain('closed')
            ->and(SalesInvoice::count())->toBe(0);
    });

    it('tells the screen who sees the date box', function () {
        $props = fn (User $user) => $this->actingAs($user)->get('/sales/counter')->assertOk()->viewData('page')['props'];

        expect($props(($this->userAs)('Manager'))['canSetSaleDate'])->toBeTrue()
            ->and($props($this->cashier)['canSetSaleDate'])->toBeFalse()
            ->and($props($this->cashier)['today'])->toBe('2026-10-25');
    });
});
