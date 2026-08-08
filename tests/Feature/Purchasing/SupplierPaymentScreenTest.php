<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Paying a supplier, through the real HTTP layer.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. `RecordSupplierPayment` was complete, correct and
 * covered by its own tests since 3 August - and had **no route and no
 * controller**, so no user could reach it. Money could enter this ERP and could
 * not leave it. The engine tests all entered below the controller and were
 * green the whole time, which is the sixth occurrence of that pattern on this
 * project.
 *
 * ⚠ So these tests go through the HTTP layer on purpose, and assert on the
 * LEDGER rather than the response. A 302 proves the route exists; only the
 * journal proves the money moved.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Pay Screen Co', 'slug' => 'pay-screen-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-pay-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'CAP-38', 'name' => 'Bottle cap 38mm',
        'type' => ProductType::RawMaterial,
    ]);

    $this->supplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Chittagong Caps', 'code' => 'SUP-002',
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $warehouse,
        lines: [['product' => $product, 'quantity' => '100', 'unit_cost' => '20.00']],
        createdBy: $this->owner,
    );
    $order->approvalRequest->approve($this->owner);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->owner);

    $this->bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-5001',
        billDate: Carbon::parse('2026-08-02'),
        lines: [['line' => $line, 'quantity' => '100', 'unitCost' => '20.00']],
        createdBy: $this->owner,
        purchaseOrder: $order,
    );

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    $this->payload = fn (array $overrides = []) => array_replace([
        'supplier_id' => $this->supplier->id,
        'money_account_id' => $this->bank->id,
        'paid_on' => '2026-08-03',
        'reference' => 'TRX-88213',
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '2000.00']],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * ⚠⚠ THE TEST THAT WOULD HAVE CAUGHT THE WHOLE GAP. Not "does the action work"
 * but "can a person reach it".
 */
it('pays a supplier through the screen and moves the money in the ledger', function () {
    expect(($this->balanceOn)('accounts_payable'))->toBe(-200000);

    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)())
        ->assertRedirect();

    expect(SupplierPayment::count())->toBe(1);

    $payment = SupplierPayment::first();

    expect((float) $payment->amount)->toBe(2000.0)
        ->and($payment->reference)->toBe('TRX-88213')
        ->and($payment->supplier_id)->toBe($this->supplier->id)
        ->and($payment->business_id)->toBe($this->business->id)
        ->and($payment->created_by)->toBe($this->owner->id);

    // ⚠ The ledger, not the response. Dr Accounts Payable, Cr the bank.
    expect(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-200000)
        ->and($this->bill->fresh()->isSettled())->toBeTrue();

    $journal = Journal::where('source_type', (new SupplierPayment)->getMorphClass())->first();

    expect($journal)->not->toBeNull()
        ->and($journal->balance()->isZero())->toBeTrue();
});

it('redirects to the payment it just created', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)())
        ->assertRedirect('/purchasing/payments/'.SupplierPayment::first()->id);
});

it('records a part payment and leaves the rest outstanding', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)([
            'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '750.00']],
        ]))
        ->assertRedirect();

    expect($this->bill->fresh()->outstanding()->format())->toBe('1,250.00')
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-125000);
});

/**
 * ⚠⚠ OVERPAYMENT IS REFUSED, and refused by the ACTION rather than by a second
 * rule in the controller. Paying more than is owed is an advance to the
 * supplier: a different event, with a different account.
 */
it('refuses to pay more than the bill has outstanding', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)([
            'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '2500.00']],
        ]))
        ->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-200000)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(0);
});

it('refuses a second payment that would overpay what is left', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)([
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '1500.00']],
    ]))->assertRedirect();

    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)([
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '600.00']],
    ]))->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(1)
        ->and($this->bill->fresh()->outstanding()->format())->toBe('500.00');
});

// ⚠ A payment that allocates nothing is not a payment.
it('refuses a payment with no allocations', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)(['allocations' => []]))
        ->assertSessionHasErrors('allocations');

    expect(SupplierPayment::count())->toBe(0);
});

it('refuses a payment where every line is zero', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)([
            'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '0']],
        ]))
        ->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(0);
});

/**
 * ⚠⚠ THE PERIOD LOCK IS THE ACTION'S, AND THE SCREEN INHERITS IT. A payment
 * dated into a closed month would change a figure somebody has already
 * reported.
 */
it('refuses a payment dated into a closed period', function () {
    // ⚠ One lock per business, "closed through this date", rather than a row
    // per month. Everything on or before 31 July is now sealed.
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->owner);

    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)(['paid_on' => '2026-07-15']))
        ->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(0);
});

// ⚠⚠ Permission, not just authentication.
it('blocks a user without purchasing.payment.record from paying', function () {
    ($this->asRole)('Staff')
        ->post('/purchasing/payments', ($this->payload)())
        ->assertForbidden();

    expect(SupplierPayment::count())->toBe(0);
});

it('blocks Staff from opening the payment form', function () {
    ($this->asRole)('Staff')->get('/purchasing/payments/new')->assertForbidden();
});

it('lets Staff read the payment history', function () {
    ($this->asRole)('Staff')->get('/purchasing/payments')->assertOk();
});

/**
 * ⚠⚠ THE PROP THE BUTTON IS GATED ON, asserted by name.
 *
 * The first version of the page read `auth.permissions` and checked whether the
 * array contained the permission. There is no such shared prop in this app - it
 * shares named booleans - so the value was always undefined and the only link to
 * the payment form NEVER RENDERED, for anybody. Every test here still passed,
 * because they all posted to the route directly rather than looking for the way
 * in. Found by opening the page in a browser; this test is what would have found
 * it first.
 */
it('tells the page whether this user may record a payment', function () {
    $this->actingAs($this->owner)
        ->get('/purchasing/payments')
        ->assertInertia(fn ($page) => $page->where('canRecordSupplierPayment', true));

    ($this->asRole)('Staff')
        ->get('/purchasing/payments')
        ->assertInertia(fn ($page) => $page->where('canRecordSupplierPayment', false));
});

it('lets an Owner open the form and see the open bills', function () {
    $this->actingAs($this->owner)
        ->get('/purchasing/payments/new?supplier_id='.$this->supplier->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/payments/create')
            ->has('bills', 1)
            ->where('bills.0.bill_number', 'INV-5001')
            // ⚠ Two decimals: this is Money::toTaka(), not the raw decimal(15,4)
            // column. The form displays it and the clerk types against it, so
            // the shape shown here is part of the contract.
            ->where('bills.0.outstanding', '2000.00')
        );
});

// ⚠ A settled bill must drop off the form, or it invites a double payment.
it('stops offering a bill once it is fully paid', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->get('/purchasing/payments/new?supplier_id='.$this->supplier->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('bills', 0));
});

it('shows the payment, its allocations and the journal it posted', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->get('/purchasing/payments/'.SupplierPayment::first()->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/payments/show')
            ->where('payment.reference', 'TRX-88213')
            ->has('allocations', 1)
            ->where('allocations.0.bill.bill_number', 'INV-5001')
            // ⚠ Presence only. What is actually IN the lines is asserted by
            // "sends journal lines the page can actually render" - this one
            // passed while both money columns were blank.
            ->has('journal.lines', 2)
        );
});

/**
 * ⚠⚠⚠ THREE DEFECTS A BROWSER FOUND AND 22 GREEN TESTS DID NOT. All three were
 * display-only - the database was correct throughout - and all three would have
 * been seen by the first person to look at the page.
 *
 * 1. The DATE WAS A DAY EARLY. `paid_on` is a `date` cast, serialised to JSON in
 *    UTC; Dhaka is UTC+6, so midnight local is 18:00 the previous day and the
 *    screen showed a payment made on the 8th as the 7th.
 * 2. The JOURNAL'S MONEY COLUMNS WERE BLANK. There are no `debit`/`credit`
 *    columns - `amount_minor` is signed paisa - so both cells rendered empty
 *    while the accounts listed correctly. The old test asserted the journal had
 *    two lines, which was true and useless.
 * 3. Money came through as the raw `decimal(15,4)`, so a payment of 750 taka
 *    read "750.0000".
 */
it('sends a calendar date that cannot shift timezone', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)([
        'paid_on' => '2026-08-03',
    ]))->assertRedirect();

    $payment = SupplierPayment::first();

    // The stored value is right; this is about what reaches the screen.
    expect($payment->paid_on->toDateString())->toBe('2026-08-03');

    $this->actingAs($this->owner)
        ->get('/purchasing/payments/'.$payment->id)
        ->assertInertia(fn ($page) => $page
            ->where('payment.paid_on', '2026-08-03')
            ->where('allocations.0.bill.bill_date', '2026-08-02')
        );

    $this->actingAs($this->owner)
        ->get('/purchasing/payments')
        ->assertInertia(fn ($page) => $page->where('payments.data.0.paid_on_date', '2026-08-03'));
});

it('sends money formatted, not as a raw four-decimal column', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->get('/purchasing/payments/'.SupplierPayment::first()->id)
        ->assertInertia(fn ($page) => $page
            ->where('payment.amount', '2000.00')
            ->where('allocations.0.amount', '2000.00')
            ->where('allocations.0.bill.total', '2000.00')
        );

    $this->actingAs($this->owner)
        ->get('/purchasing/payments')
        ->assertInertia(fn ($page) => $page->where('payments.data.0.amount_taka', '2000.00'));
});

it('sends journal lines the page can actually render', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->get('/purchasing/payments/'.SupplierPayment::first()->id)
        ->assertOk()
        ->assertInertia(function ($page) {
            $lines = collect($page->toArray()['props']['journal']['lines']);

            // ⚠ The FIELD the page reads, and a real signed value in it - not
            // just "there are two lines".
            expect($lines)->toHaveCount(2)
                ->and($lines->every(fn ($line) => array_key_exists('amount_minor', $line)))->toBeTrue()
                ->and($lines->firstWhere('amount_minor', 200000))->not->toBeNull()
                ->and($lines->firstWhere('amount_minor', -200000))->not->toBeNull();
        });
});

it('lists a recorded payment', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)
        ->get('/purchasing/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/payments/index')
            ->has('payments.data', 1)
            ->where('payments.data.0.reference', 'TRX-88213')
        );
});

/**
 * ⚠⚠⚠ CROSS-BUSINESS. A bare `exists:` accepts another business's id - the hole
 * found on the product screen on 5 August - and here it would pay a stranger's
 * bill out of our bank.
 */
it('refuses a supplier belonging to another business', function () {
    TenantScope::enter($this->other->id);
    $foreign = Supplier::create(['business_id' => $this->other->id, 'name' => 'Foreign Co', 'code' => 'F-1']);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)(['supplier_id' => $foreign->id]))
        ->assertSessionHasErrors('supplier_id');

    expect(SupplierPayment::count())->toBe(0);
});

it('refuses a money account belonging to another business', function () {
    TenantScope::enter($this->other->id);
    $foreignAccount = MoneyAccount::create([
        'business_id' => $this->other->id,
        'name' => 'Their Bank',
        'type' => MoneyAccountType::Bank,
    ]);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)(['money_account_id' => $foreignAccount->id]))
        ->assertSessionHasErrors('money_account_id');

    expect(SupplierPayment::count())->toBe(0);
});

it('refuses a bill belonging to another supplier', function () {
    $otherSupplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Someone Else', 'code' => 'SUP-999',
    ]);

    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)(['supplier_id' => $otherSupplier->id]))
        ->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(0);
});

// ⚠ The same bill twice in one submission would pass each line's own
// outstanding check and together overpay the bill.
it('refuses the same bill twice in one payment', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/payments', ($this->payload)([
            'allocations' => [
                ['supplier_bill_id' => $this->bill->id, 'amount' => '1500.00'],
                ['supplier_bill_id' => $this->bill->id, 'amount' => '1500.00'],
            ],
        ]))
        ->assertSessionHasErrors();

    expect(SupplierPayment::count())->toBe(0);
});

it('does not leak another business payments into the list', function () {
    $this->actingAs($this->owner)->post('/purchasing/payments', ($this->payload)())->assertRedirect();

    TenantScope::enter($this->other->id);

    expect(SupplierPayment::count())->toBe(0);

    TenantScope::enter($this->business->id);
});

it('requires authentication', function () {
    $this->post('/purchasing/payments', ($this->payload)())->assertRedirect('/login');
});
