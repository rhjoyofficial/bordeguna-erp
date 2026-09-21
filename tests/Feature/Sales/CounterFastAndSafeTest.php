<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
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
use App\Modules\Sales\Actions\CheckCustomerCredit;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The counter, fast and safe (Sales UX audit #4, #6 and #13; ChatGPT's POS V1,
 * 21 Sep 2026).
 *
 * ⚠ "Enter never completes the sale" and "Enter in the search adds the exact
 * barcode" live in the browser and no test here can press a key. What CAN be
 * pinned is everything the server decides: the price is the server's, a changed
 * price needs a permission and a reason and leaves a trail, a walk-in cannot owe
 * money, credit follows the same rules as an order, "Paid into" is handed the
 * cashier's own till, and a finished sale comes back with its slip and change.
 *
 * ⚠⚠ Props are asserted as PROPS, never as session state: a flashed key is not a
 * prop until the controller passes it (dead engine #9).
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

    Carbon::setTestNow('2026-09-21 11:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop floor', 'code' => 'SHOP']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $product = fn (string $sku, string $name, ?string $barcode = null) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'barcode' => $barcode,
        'name' => $name,
        'type' => ProductType::FinishedGood,
    ]);

    $this->honey = $product('SKU-HONEY', 'Sundarban Honey 500g', '8941100500012');
    $this->ghee = $product('SKU-GHEE', 'Cow Ghee 400g');
    // Nobody priced this one: an override must not be a way to sell it.
    $this->unpriced = $product('SKU-OIL', 'Mustard Oil 1L');

    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    // A quantity break: twelve jars are cheaper each. The screen shows the
    // 1-unit price until it re-asks, so only the server can be trusted with it.
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->honey->id, 'unit_price' => '800', 'min_quantity' => '10', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->ghee->id, 'unit_price' => '1200', 'effective_from' => '2026-01-01']);

    $stocker = User::factory()->create();

    foreach ([$this->honey, $this->ghee, $this->unpriced] as $stocked) {
        app(RecordStockIn::class)->handle($stocked, $this->warehouse, '100', '500', $stocker, occurredAt: '2026-09-01 09:00:00');
    }

    // Two tills and a bank: "first alphabetically" would be Front Till, which
    // is NOT the one this cashier's shift is on.
    $this->frontTill = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Front Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    $this->backTill = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Back Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    $this->bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => Account::bySlug('cash_at_bank')->id]);

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    // One sale at the counter, as the screen sends it.
    $this->sell = function (User $user, array $overrides = []): TestResponse {
        return $this->actingAs($user)->post('/sales/counter', array_merge([
            'customer_id' => Customer::walkIn()->id,
            'warehouse_id' => $this->warehouse->id,
            'money_account_id' => $this->frontTill->id,
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '2']],
        ], $overrides));
    };

    $this->props = function (TestResponse $response): array {
        $response->assertOk();

        return $response->viewData('page')['props'];
    };

    $this->openShift = fn (User $user, MoneyAccount $till, string $status = 'open') => DB::table('pos_shifts')->insertGetId([
        'business_id' => $this->business->id,
        'money_account_id' => $till->id,
        'opened_by' => $user->id,
        'opened_at' => now(),
        'opening_float' => '1000.00',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->honeyOnHand = fn () => (float) StockBatch::where('product_id', $this->honey->id)->sum('current_quantity');
});

afterEach(fn () => Carbon::setTestNow());

describe('what the counter screen is handed', function () {
    it('gives every product its barcode, so a scanned code can be matched exactly', function () {
        $products = collect(($this->props)($this->actingAs(($this->userAs)('Staff'))->get('/sales/counter'))['products']);

        expect($products->firstWhere('id', $this->honey->id)['barcode'])->toBe('8941100500012')
            ->and($products->firstWhere('id', $this->ghee->id))->toHaveKey('barcode');
    });

    it('gives the customer picker each phone, as typed and in its matching form', function () {
        Customer::create(['business_id' => $this->business->id, 'code' => 'C-01', 'name' => 'Rahim Store', 'phone' => '+880 1711-000000']);

        $customers = collect(($this->props)($this->actingAs(($this->userAs)('Staff'))->get('/sales/counter'))['customers']);
        $rahim = $customers->firstWhere('name', 'Rahim Store');

        expect($rahim['phone'])->toBe('+880 1711-000000')
            ->and($rahim['phone_normalized'])->toBe('01711000000');
    });

    it('defaults Paid into to the till of the shift THIS cashier opened', function () {
        $cashier = ($this->userAs)('Staff');
        $colleague = ($this->userAs)('Staff');

        // A colleague holds the Front Till; this cashier holds the Back Till.
        ($this->openShift)($colleague, $this->frontTill);
        $shiftId = ($this->openShift)($cashier, $this->backTill);

        $props = ($this->props)($this->actingAs($cashier)->get('/sales/counter'));

        expect($props['myTill'])->toBe([
            'shift_id' => $shiftId,
            'money_account_id' => $this->backTill->id,
            'name' => 'Back Till',
        ]);
    });

    it('offers no till at all when the cashier has no open shift, whoever else does', function () {
        $cashier = ($this->userAs)('Staff');
        ($this->openShift)(($this->userAs)('Staff'), $this->frontTill);
        // A shift of their own that is already closed is not a till either.
        ($this->openShift)($cashier, $this->backTill, 'closed');

        expect(($this->props)($this->actingAs($cashier)->get('/sales/counter'))['myTill'])->toBeNull();
    });

    it('tells the screen who may change a price', function () {
        expect(($this->props)($this->actingAs(($this->userAs)('Manager'))->get('/sales/counter'))['canOverridePrice'])->toBeTrue()
            ->and(($this->props)($this->actingAs(($this->userAs)('Owner'))->get('/sales/counter'))['canOverridePrice'])->toBeTrue()
            ->and(($this->props)($this->actingAs(($this->userAs)('Staff'))->get('/sales/counter'))['canOverridePrice'])->toBeFalse();
    });
});

describe('the server prices the sale', function () {
    it('prices a line the screen did not change from the list, at the quantity sold', function () {
        // Twelve jars and no price sent: the screen may still be showing the
        // 1-unit price, but the sale is billed at the 10-and-over break.
        ($this->sell)(($this->userAs)('Staff'), ['lines' => [['product_id' => $this->honey->id, 'quantity' => '12']]])
            ->assertSessionHasNoErrors();

        $line = SalesInvoiceLine::sole();

        expect((string) $line->unit_price)->toBe('800.0000')
            ->and((string) SalesInvoice::sole()->total)->toBe('9600.0000')
            // An untouched line carries no trail at all.
            ->and($line->list_price)->toBeNull()
            ->and($line->price_overridden)->toBeFalse()
            ->and($line->override_reason)->toBeNull()
            ->and($line->overridden_by)->toBeNull();
    });

    it('treats a price the screen sends that equals the list as the list price, not a change', function () {
        ($this->sell)(($this->userAs)('Staff'), ['lines' => [['product_id' => $this->honey->id, 'quantity' => '2', 'unit_price' => '850.00']]])
            ->assertSessionHasNoErrors();

        expect(SalesInvoiceLine::sole()->price_overridden)->toBeFalse()
            ->and(SalesInvoiceLine::sole()->list_price)->toBeNull();
    });

    it('refuses a changed price from someone without the permission, says the list price, and records nothing', function () {
        ($this->sell)(($this->userAs)('Staff'), ['lines' => [['product_id' => $this->honey->id, 'quantity' => '2', 'unit_price' => '500']]])
            ->assertSessionHasErrors('lines.0.unit_price');

        expect(session('errors')->first('lines.0.unit_price'))->toContain('price-override permission')->toContain('850.00')
            ->and(SalesInvoice::count())->toBe(0)
            ->and(($this->honeyOnHand)())->toBe(100.0);
    });

    it('refuses a line discount and a bill discount from someone without the permission', function () {
        ($this->sell)(($this->userAs)('Staff'), [
            'invoice_discount' => '100',
            'lines' => [
                ['product_id' => $this->honey->id, 'quantity' => '1'],
                ['product_id' => $this->ghee->id, 'quantity' => '1', 'discount' => '50'],
            ],
        ])->assertSessionHasErrors(['lines.1.discount', 'invoice_discount'])->assertSessionDoesntHaveErrors('lines.0.discount');

        expect(SalesInvoice::count())->toBe(0);
    });

    it('asks even a Manager for a reason before a price is changed or a discount given', function () {
        ($this->sell)(($this->userAs)('Manager'), [
            'invoice_discount' => '20',
            'lines' => [
                ['product_id' => $this->honey->id, 'quantity' => '2', 'unit_price' => '800'],
                ['product_id' => $this->ghee->id, 'quantity' => '1', 'discount' => '50', 'override_reason' => '  '],
            ],
        ])->assertSessionHasErrors(['lines.0.override_reason', 'lines.1.override_reason', 'discount_reason']);

        expect(SalesInvoice::count())->toBe(0);
    });

    it('records a changed price with the list price, the reason and who changed it', function () {
        $manager = ($this->userAs)('Manager');

        ($this->sell)($manager, ['lines' => [
            ['product_id' => $this->honey->id, 'quantity' => '2', 'unit_price' => '800', 'override_reason' => 'Regular customer, agreed last week'],
        ]])->assertSessionHasNoErrors();

        $line = SalesInvoiceLine::sole();

        expect((string) $line->unit_price)->toBe('800.0000')
            ->and((string) $line->list_price)->toBe('850.0000')
            ->and($line->price_overridden)->toBeTrue()
            ->and($line->override_reason)->toBe('Regular customer, agreed last week')
            ->and($line->overridden_by)->toBe($manager->id)
            ->and((string) SalesInvoice::sole()->total)->toBe('1600.0000');
    });

    it('records a discounted line as discounted at its list price, with the reason and who', function () {
        $owner = ($this->userAs)('Owner');

        ($this->sell)($owner, ['lines' => [
            ['product_id' => $this->ghee->id, 'quantity' => '1', 'discount' => '100', 'override_reason' => 'Dented tin'],
        ]])->assertSessionHasNoErrors();

        $line = SalesInvoiceLine::sole();

        expect($line->price_overridden)->toBeFalse()
            ->and((string) $line->list_price)->toBe('1200.0000')
            ->and((string) $line->line_discount)->toBe('100.0000')
            ->and($line->override_reason)->toBe('Dented tin')
            ->and($line->overridden_by)->toBe($owner->id);
    });

    it('records a bill discount with its reason and who gave it, and shows both on the invoice page', function () {
        $manager = ($this->userAs)('Manager');

        ($this->sell)($manager, ['invoice_discount' => '50', 'discount_reason' => 'Festival offer'])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::sole();

        expect((string) $invoice->invoice_discount)->toBe('50.0000')
            ->and($invoice->discount_reason)->toBe('Festival offer')
            ->and($invoice->discount_given_by)->toBe($manager->id);

        $shown = ($this->props)($this->actingAs($manager)->get("/sales/invoices/{$invoice->id}"))['invoice'];

        expect($shown['discount_reason'])->toBe('Festival offer')
            ->and($shown['discount_given_by']['name'])->toBe($manager->name);
    });

    it('shows a changed price on the invoice page with the list price, the reason and who', function () {
        $manager = ($this->userAs)('Manager');

        ($this->sell)($manager, ['lines' => [
            ['product_id' => $this->honey->id, 'quantity' => '1', 'unit_price' => '820', 'override_reason' => 'Price match'],
        ]])->assertSessionHasNoErrors();

        $line = ($this->props)($this->actingAs($manager)->get('/sales/invoices/'.SalesInvoice::sole()->id))['invoice']['lines'][0];

        expect($line['list_price'])->toBe('850.0000')
            ->and($line['price_overridden'])->toBeTrue()
            ->and($line['override_reason'])->toBe('Price match')
            ->and($line['overridden_by']['name'])->toBe($manager->name);
    });

    it('never lets an override price a product nobody priced', function () {
        ($this->sell)(($this->userAs)('Owner'), ['lines' => [
            ['product_id' => $this->unpriced->id, 'quantity' => '1', 'unit_price' => '150', 'override_reason' => 'Customer insists'],
        ]])->assertSessionHasErrors('lines');

        expect(session('errors')->first('lines'))->toContain('Mustard Oil 1L')
            ->and(SalesInvoice::count())->toBe(0);
    });

    it('refuses a price that is not a plain number with a sentence, not a 500', function () {
        ($this->sell)(($this->userAs)('Owner'), ['lines' => [
            ['product_id' => $this->honey->id, 'quantity' => '1', 'unit_price' => '8.5e2', 'override_reason' => 'x'],
        ]])->assertSessionHasErrors('lines.0.unit_price');

        expect(SalesInvoice::count())->toBe(0);
    });

    it('gives the price-override permission to Owner and Manager, and not to Staff', function () {
        expect(($this->userAs)('Owner')->can('sales.price.override'))->toBeTrue()
            ->and(($this->userAs)('Manager')->can('sales.price.override'))->toBeTrue()
            ->and(($this->userAs)('Staff')->can('sales.price.override'))->toBeFalse();
    });
});

describe('credit at the counter', function () {
    it('refuses On account for the walk-in, even from an Owner, and moves no stock', function () {
        ($this->sell)(($this->userAs)('Owner'), ['money_account_id' => null])
            ->assertSessionHasErrors('money_account_id');

        expect(session('errors')->first('money_account_id'))->toContain('walk-in customer cannot take goods on account')
            ->and(SalesInvoice::count())->toBe(0)
            ->and(($this->honeyOnHand)())->toBe(100.0);
    });

    it('lets a named customer take goods on account within their credit limit, counting this sale once', function () {
        // A limit of exactly one jar: counted twice, it would be refused.
        $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-02', 'name' => 'Karim Traders', 'type' => CustomerType::Retail, 'credit_limit' => '850']);

        ($this->sell)(($this->userAs)('Staff'), [
            'customer_id' => $shop->id,
            'money_account_id' => null,
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']],
        ])->assertSessionHasNoErrors();

        expect(SalesInvoice::sole()->outstanding()->toTaka())->toBe('850.00');
    });

    it('refuses a sale on account that would take a customer over their limit, and takes the whole sale back', function () {
        $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-03', 'name' => 'Karim Traders', 'type' => CustomerType::Retail, 'credit_limit' => '1000']);
        $cashier = ($this->userAs)('Staff');

        $onAccount = fn () => ($this->sell)($cashier, [
            'customer_id' => $shop->id,
            'money_account_id' => null,
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']],
        ]);

        $onAccount()->assertSessionHasNoErrors();
        $onAccount()->assertSessionHasErrors('money_account_id');

        expect(session('errors')->first('money_account_id'))
            ->toContain('would owe 1,700.00 with this sale, over their credit limit of 1,000.00')
            ->toContain('Take the payment now')
            // Only the first sale stands: no second invoice, no second jar gone.
            ->and(SalesInvoice::count())->toBe(1)
            ->and(($this->honeyOnHand)())->toBe(99.0);

        // Paid, the same sale goes through: the limit is about credit only.
        ($this->sell)($cashier, ['customer_id' => $shop->id, 'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']]])
            ->assertSessionHasNoErrors();

        expect(SalesInvoice::count())->toBe(2);
    });

    it('refuses a distributor on account whatever their limit, and sells to them when they pay', function () {
        $distributor = Customer::create(['business_id' => $this->business->id, 'code' => 'D-01', 'name' => 'Chattogram Distribution', 'type' => CustomerType::Distributor, 'credit_limit' => '1000000']);

        ($this->sell)(($this->userAs)('Owner'), ['customer_id' => $distributor->id, 'money_account_id' => null])
            ->assertSessionHasErrors('money_account_id');

        expect(session('errors')->first('money_account_id'))->toContain('distributors pay in advance')
            ->and(SalesInvoice::count())->toBe(0);

        ($this->sell)(($this->userAs)('Owner'), ['customer_id' => $distributor->id, 'money_account_id' => $this->bank->id])
            ->assertSessionHasNoErrors();

        expect(SalesInvoice::count())->toBe(1);
    });

    it('asks the same exposure the order approval asks, counted once whichever side of the write it is asked', function () {
        $shop = Customer::create(['business_id' => $this->business->id, 'code' => 'C-04', 'name' => 'Savar Store', 'type' => CustomerType::Retail, 'credit_limit' => '1000']);

        ($this->sell)(($this->userAs)('Staff'), ['customer_id' => $shop->id, 'money_account_id' => null, 'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']]])
            ->assertSessionHasNoErrors();

        $credit = app(CheckCustomerCredit::class);

        // Owes 850. Asked BEFORE a new 100 is written: 950, within 1,000.
        expect($credit->refusalForAmount($shop, Money::ofTaka('100'), alreadyOwed: false))->toBeNull()
            // ...and 200 more would be 1,050.
            ->and($credit->refusalForAmount($shop, Money::ofTaka('200'), alreadyOwed: false)?->owedAfter->toTaka())->toBe('1050.00');

        // Asked AFTER the 850 was written: that 850 is already in what is owed.
        $refusal = $credit->refusalForAmount($shop, Money::ofTaka('850'), alreadyOwed: true);
        expect($refusal)->toBeNull();
    });
});

describe('after the sale', function () {
    it('comes back to a fresh counter handing it the slip and the change, once', function () {
        $cashier = ($this->userAs)('Staff');

        ($this->sell)($cashier, ['cash_given' => '2000'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.counter.create'));

        $invoice = SalesInvoice::sole();
        $sale = ($this->props)($this->actingAs($cashier)->get('/sales/counter'))['lastSale'];

        expect($sale)->toBe([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => '1700.00',
            'on_account' => false,
            'slip_url' => route('sales.invoices.slip', $invoice->id),
            'cash_given' => '2000.00',
            'change' => '300.00',
        ]);

        // The next visit is a counter with nothing to report.
        expect(($this->props)($this->actingAs($cashier)->get('/sales/counter'))['lastSale'])->toBeNull();

        // And the slip opens for the person who sold it.
        $this->actingAs($cashier)->get($sale['slip_url'])->assertOk();
    });

    it('refuses cash given below the total, and records nothing', function () {
        ($this->sell)(($this->userAs)('Staff'), ['cash_given' => '1000'])
            ->assertSessionHasErrors('cash_given');

        expect(session('errors')->first('cash_given'))->toContain('less than the total of 1,700.00')
            ->and(SalesInvoice::count())->toBe(0);
    });

    it('ignores cash given when the money is not going into a cash till', function () {
        $cashier = ($this->userAs)('Staff');

        ($this->sell)($cashier, ['money_account_id' => $this->bank->id, 'cash_given' => '5'])
            ->assertSessionHasNoErrors();

        $sale = ($this->props)($this->actingAs($cashier)->get('/sales/counter'))['lastSale'];

        expect($sale['cash_given'])->toBeNull()
            ->and($sale['change'])->toBeNull();
    });

    it('keeps the reason with a held discount, so a resumed cart still says why', function () {
        $this->actingAs(($this->userAs)('Manager'))->post('/sales/counter/hold', [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->ghee->id, 'quantity' => '1', 'discount' => '100', 'override_reason' => 'Dented tin']],
        ])->assertSessionHasNoErrors();

        $payload = json_decode(DB::table('pos_held_carts')->value('payload'), true);

        expect($payload['lines'][0]['override_reason'])->toBe('Dented tin');
    });
});
