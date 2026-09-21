<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 0 of the Sales re-plan, "stop the bleeding" (Sales UX audit #1, #2 and
 * #12, 21 Sep 2026): every refusal reaches the person as a sentence on a key the
 * screen shows, and the two lists that answer "who owes us" and "what is not
 * billed yet" filter the whole book, not the page already fetched.
 *
 * ⚠⚠ As in SalesRefusalSurfaceTest, every assertion is about the SURFACE. None
 * of these writes changed whether it is refused; the engine tests prove that.
 * Before this, each one below was either a 500 page or a refusal written on a
 * key no page rendered, which looks to the user exactly like a dead button.
 *
 * ⚠ The keys asserted here are the keys the pages read. Rename one on either
 * side and the refusal goes silent again, so they are pinned on purpose.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Silent Co', 'slug' => 'silent-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $product = fn (string $sku, string $name) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => ProductType::FinishedGood,
    ]);

    $this->honey = $product('SKU-HONEY', 'Sundarban Honey 500g');
    $this->ghee = $product('SKU-GHEE', 'Cow Ghee 400g');
    // Nothing prices this one: the realistic first-morning case.
    $this->unpriced = $product('SKU-OIL', 'Mustard Oil 1L');

    foreach ([[$this->honey, '850'], [$this->ghee, '1200']] as [$priced, $price]) {
        ProductPrice::create([
            'business_id' => $this->business->id,
            'product_id' => $priced->id,
            'unit_price' => $price,
            'effective_from' => '2026-01-01',
        ]);
    }

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
        'credit_limit' => '1000000',
    ]);

    // One account the ledger can book into, and one it cannot: the till an
    // older setup left without a ledger head.
    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Cash in Hand',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);
    $this->noLedger = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Old Till',
        'type' => 'cash',
    ]);

    Carbon::setTestNow('2026-09-10 10:00:00');

    $this->clerk = User::factory()->create();

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    foreach ([$this->honey, $this->ghee] as $stocked) {
        app(RecordStockIn::class)->handle(
            $stocked, $this->warehouse, '100', '500',
            $this->clerk, occurredAt: '2026-08-01 09:00:00'
        );
    }

    // A plain bill for a service, so a test can own as many open invoices as it
    // needs without moving any stock.
    $this->invoice = fn (string $amount, string $on = '2026-09-10') => app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: [['description' => 'Carriage', 'quantity' => '1', 'unitPrice' => $amount, 'discount' => '0']],
        createdBy: $this->clerk,
        invoiceDate: $on,
    );

    $this->receive = fn (SalesInvoice $invoice, string $amount) => app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $this->cash,
        receivedOn: $invoice->invoice_date,
        allocations: [['invoice' => $invoice, 'amount' => $amount]],
        createdBy: $this->clerk,
    );

    // An Owner's order is approved as it is raised.
    $this->order = fn (array $lines) => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: $lines,
        createdBy: tap(User::factory()->create(), function (User $owner) {
            $owner->businesses()->attach($this->business->id);
            setPermissionsTeamId($this->business->id);
            $owner->assignRole('Owner');
        }),
    );

    // ⚠ The PROP, not the session: what the page is actually handed.
    $this->props = fn (TestResponse $response): array => $response->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

describe('refusals that used to be a 500 page', function () {
    it('says a receipt cannot go into an account with no ledger, on the account field', function () {
        $invoice = ($this->invoice)('1000');

        ($this->asRole)('Manager')->post("/sales/invoices/{$invoice->id}/receipts", [
            'money_account_id' => $this->noLedger->id,
            'amount' => '500',
            'received_on' => '2026-09-10',
        ])->assertRedirect()->assertSessionHasErrors('money_account_id');

        expect(session('errors')->first('money_account_id'))->toContain('Old Till has no ledger account yet')
            ->and(CustomerReceipt::count())->toBe(0);
    });

    it('says the month is closed on the date field, instead of failing blank', function () {
        $invoice = ($this->invoice)('1000', '2026-08-20');
        AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->clerk);

        ($this->asRole)('Manager')->post("/sales/invoices/{$invoice->id}/receipts", [
            'money_account_id' => $this->cash->id,
            'amount' => '500',
            'received_on' => '2026-08-25',
        ])->assertRedirect()->assertSessionHasErrors('received_on');

        expect(CustomerReceipt::count())->toBe(0);
    });

    it('refuses a quotation that nothing prices with a sentence naming the product, and saves nothing', function () {
        ($this->asRole)('Manager')->post('/sales/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => '2026-09-30',
            'lines' => [['product_id' => $this->unpriced->id, 'quantity' => '5']],
        ])->assertRedirect()->assertSessionHasErrors('lines');

        expect(session('errors')->first('lines'))->toContain('Mustard Oil 1L')
            ->and(DB::table('quotations')->count())->toBe(0)
            ->and(DB::table('quotation_lines')->count())->toBe(0);
    });

    it('still saves a quotation for that product when a price is typed', function () {
        ($this->asRole)('Manager')->post('/sales/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => '2026-09-30',
            'lines' => [['product_id' => $this->unpriced->id, 'quantity' => '5', 'unit_price' => '320']],
        ])->assertSessionHasNoErrors();

        expect(DB::table('quotations')->count())->toBe(1);
    });

    it('says why a quotation cannot be accepted, on the key the list shows', function () {
        ($this->asRole)('Manager')->post('/sales/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => '2026-09-30',
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '5', 'unit_price' => '800']],
        ]);
        $this->customer->update(['is_active' => false]);

        ($this->asRole)('Manager')->post('/sales/quotations/'.DB::table('quotations')->value('id').'/accept')
            ->assertRedirect()->assertSessionHasErrors('quotation');

        expect(session('errors')->first('quotation'))->toContain('no longer active')
            ->and(SalesOrder::count())->toBe(0);
    });

    it('refuses a second approval with a sentence, not a 500', function () {
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'has already been reviewed'));
    });

    it('refuses to confirm an order still waiting for its online payment', function () {
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);
        $order->approvalRequest->delete();

        ($this->asRole)('Manager')->post("/sales/orders/{$order->fresh()->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'waiting for its online payment'));
    });

    it('refuses to cancel an order that has goods out against it', function () {
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);
        app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '4', 'orderLine' => $order->lines->first()]],
            createdBy: $this->clerk,
        );

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/cancel")
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'close it short instead'));

        expect($order->fresh()->status)->toBe(SalesOrderStatus::PartiallyDelivered);
    });

    it('refuses to close short an order nothing was delivered against', function () {
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/close", ['reason' => 'Customer changed his mind'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'only a partially delivered order can be closed short'));

        expect($order->fresh()->status)->toBe(SalesOrderStatus::Open);
    });

    it('asks for the reason on the key the order page shows', function () {
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);
        app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '4', 'orderLine' => $order->lines->first()]],
            createdBy: $this->clerk,
        );

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/close", ['reason' => ''])
            ->assertRedirect()->assertSessionHasErrors('reason');

        expect($order->fresh()->status)->toBe(SalesOrderStatus::PartiallyDelivered);
    });

    it('says a counter sale cannot be paid into an account with no ledger, on the paid-into field', function () {
        ($this->asRole)('Staff')->post('/sales/counter', [
            'customer_id' => Customer::walkIn()->id,
            'warehouse_id' => $this->warehouse->id,
            'money_account_id' => $this->noLedger->id,
            'lines' => [['product_id' => $this->honey->id, 'quantity' => '1']],
        ])->assertRedirect()->assertSessionHasErrors('money_account_id');

        expect(session('errors')->first('money_account_id'))->toContain('Old Till has no ledger account yet')
            ->and(SalesInvoice::count())->toBe(0);
    });
});

describe('refusals that used to land on a key no page rendered', function () {
    // ⚠⚠ The commonest warehouse mistake. The action numbers only the lines that
    // carry a quantity, so this puts the refusal back on the FORM row: row 0 is
    // skipped (quantity 0), and the over-delivery is row 1.
    it('puts an over-delivery on the row that asked for it', function () {
        $order = ($this->order)([
            ['product' => $this->honey, 'quantity' => '10'],
            ['product' => $this->ghee, 'quantity' => '5'],
        ]);
        [$honeyLine, $gheeLine] = [$order->lines->firstWhere('product_id', $this->honey->id), $order->lines->firstWhere('product_id', $this->ghee->id)];

        ($this->asRole)('Staff')->post("/sales/orders/{$order->id}/deliver", [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['sales_order_line_id' => $honeyLine->id, 'quantity' => '0'],
                ['sales_order_line_id' => $gheeLine->id, 'quantity' => '9'],
            ],
        ])->assertRedirect()->assertSessionHasErrors('lines.1.quantity')->assertSessionDoesntHaveErrors(['lines.0.quantity', 'lines.0.orderLine']);

        expect(session('errors')->first('lines.1.quantity'))->toContain('Cow Ghee 400g')
            ->and((float) $gheeLine->fresh()->quantity_delivered)->toBe(0.0);
    });

    it('shows an over-receipt on the key the amount box reads', function () {
        $invoice = ($this->invoice)('1000');

        ($this->asRole)('Manager')->post("/sales/invoices/{$invoice->id}/receipts", [
            'money_account_id' => $this->cash->id,
            'amount' => '5000',
            'received_on' => '2026-09-10',
        ])->assertSessionHasErrors('allocations.0.amount');

        expect(CustomerReceipt::count())->toBe(0);
    });

    it('puts an over-refund on the field the cashier typed it in, and records no return', function () {
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '10']],
            createdBy: $this->clerk,
        );

        // Nothing was invoiced, so the return creates no credit to refund.
        ($this->asRole)('Manager')->post('/sales/returns', [
            'delivery_id' => $delivery->id,
            'lines' => [['delivery_line_id' => $delivery->lines->first()->id, 'quantity_to_stock' => '2']],
            'refund' => ['money_account_id' => $this->cash->id, 'amount' => '100'],
        ])->assertRedirect()->assertSessionHasErrors('refund.amount')->assertSessionDoesntHaveErrors('amount');

        expect(SalesReturn::count())->toBe(0);
    });

    it('shares a warning flash with the page', function () {
        // ⚠ DiscountController's "saved, but another rule outranks it" was
        // flashed as `warning` and never shared, so nobody ever saw it.
        $response = ($this->asRole)('Manager')->withSession(['warning' => 'Another rule outranks this discount.'])->get('/sales/invoices');

        expect(($this->props)($response)['flash']['warning'])->toBe('Another rule outranks this discount.');
    });
});

describe('pickers offer only accounts the ledger can book into', function () {
    it('leaves the account with no ledger out of every receipt and refund picker', function () {
        $invoice = ($this->invoice)('1000');
        $order = ($this->order)([['product' => $this->honey, 'quantity' => '10']]);
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '1']],
            createdBy: $this->clerk,
        );

        $manager = ($this->asRole)('Manager');

        $pickers = [
            collect(($this->props)($manager->get("/sales/invoices/{$invoice->id}"))['moneyAccounts']),
            collect(($this->props)($manager->get('/sales/counter'))['moneyAccounts']),
            collect(($this->props)($manager->get("/sales/orders/{$order->id}"))['money_accounts']),
            collect(($this->props)($manager->get("/sales/returns/create?delivery_id={$delivery->id}"))['moneyAccounts']),
        ];

        foreach ($pickers as $offered) {
            expect($offered->pluck('name')->all())->toBe(['Cash in Hand']);
        }
    });
});

describe('lists you can trust', function () {
    // ⚠⚠ The shape that hid debts: the unpaid invoices are the OLDEST, and the
    // newest twenty are all paid. Filtering the fetched page found almost
    // nothing and the pager still counted every invoice.
    it('filters Unpaid over the whole book before it paginates', function () {
        $unpaid = collect(['1000', '2000', '3000'])->map(fn ($amount) => ($this->invoice)($amount, '2026-09-01'));

        foreach (range(1, 20) as $i) {
            ($this->receive)(($this->invoice)('500', '2026-09-05'), '500');
        }

        // Part paid is still unpaid.
        $partPaid = ($this->invoice)('1000', '2026-09-06');
        ($this->receive)($partPaid, '100');

        // Settled by a return rather than a receipt: the credit counts too.
        $sale = app(RecordCounterSale::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '1']],
            createdBy: $this->clerk,
        );
        app(RecordSalesReturn::class)->handle(
            delivery: $sale->delivery,
            lines: [['deliveryLine' => $sale->delivery->lines->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
            createdBy: $this->clerk,
        );
        expect($sale->invoice->fresh()->isSettled())->toBeTrue();

        $manager = ($this->asRole)('Manager');

        $open = ($this->props)($manager->get('/sales/invoices?status=open'))['invoices'];
        expect($open['total'])->toBe(4)
            ->and(collect($open['data'])->pluck('id')->sort()->values()->all())
            ->toBe($unpaid->pluck('id')->push($partPaid->id)->sort()->values()->all())
            ->and(collect($open['data'])->every(fn (array $row) => $row['is_settled'] === false))->toBeTrue();

        $settled = ($this->props)($manager->get('/sales/invoices?status=settled'))['invoices'];
        expect($settled['total'])->toBe(21)
            ->and(collect($settled['data'])->every(fn (array $row) => $row['is_settled'] === true))->toBeTrue()
            ->and(collect($settled['data'])->pluck('id'))->toContain($sale->invoice->id);
    });

    it('filters Not fully invoiced over every challan before it paginates', function () {
        $unbilled = collect(range(1, 3))->map(fn () => app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '1']],
            createdBy: $this->clerk,
            deliveredAt: '2026-09-02 09:00:00',
        ));

        // Twenty newer challans, each billed at the counter as it was written.
        foreach (range(1, 20) as $i) {
            app(RecordCounterSale::class)->handle(
                customer: $this->customer,
                warehouse: $this->warehouse,
                lines: [['product' => $this->ghee, 'quantity' => '1']],
                createdBy: $this->clerk,
            );
        }

        $page = ($this->props)(($this->asRole)('Manager')->get('/sales/deliveries?status=uninvoiced'))['deliveries'];

        expect($page['total'])->toBe(3)
            ->and(collect($page['data'])->pluck('id')->sort()->values()->all())->toBe($unbilled->pluck('id')->sort()->values()->all())
            ->and(collect($page['data'])->every(fn (array $row) => $row['fully_invoiced'] === false))->toBeTrue();
    });

    // ⚠ Dhaka dates, not UTC timestamps sliced in the browser: midnight in
    // Dhaka is 18:00 the day before in UTC, so every row read a day early.
    it('hands the lists plain Dhaka dates', function () {
        Carbon::setTestNow('2026-09-10 03:00:00');

        ($this->invoice)('1000', '2026-09-10');
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->honey, 'quantity' => '2']],
            createdBy: $this->clerk,
        );
        app(RecordSalesReturn::class)->handle(
            delivery: $delivery,
            lines: [['deliveryLine' => $delivery->lines->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
            createdBy: $this->clerk,
        );

        $manager = ($this->asRole)('Manager');

        expect(($this->props)($manager->get('/sales/invoices'))['invoices']['data'][0]['invoice_date'])->toBe('2026-09-10')
            ->and(($this->props)($manager->get('/sales/deliveries'))['deliveries']['data'][0]['delivered_at'])->toBe('2026-09-10')
            ->and(($this->props)($manager->get('/sales/returns'))['returns']['data'][0]['returned_at'])->toBe('2026-09-10');

        $prices = collect(($this->props)($manager->get('/sales/prices'))['prices']['data']);
        expect($prices->pluck('effective_from')->unique()->values()->all())->toBe(['2026-01-01']);
    });
});
