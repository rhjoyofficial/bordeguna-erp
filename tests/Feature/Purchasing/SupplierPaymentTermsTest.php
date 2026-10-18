<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\PaymentTermType;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\SupplierCredit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Structured supplier payment terms and the credit warning (ruling P6,
 * 18 Oct 2026; minimum tests 33 and 34).
 *
 * - Each kind of terms gives the right due date, month ends included.
 * - The bill keeps the date it was recorded with: changing the supplier's
 *   terms later moves nothing. An override, and a later change, need a reason
 *   and are in the bill's history.
 * - The credit warning keeps billed AP, received-not-billed, open orders and
 *   advances apart, warns at 80% and over the limit, and never blocks.
 * - The AP ageing and the bills-due list count from the stored due date.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-20 10:00:00');

    $this->business = Business::create(['name' => 'Terms Co', 'slug' => 'terms-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    $this->seed(PurchasingPermissionsSeeder::class);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500', 'name' => 'Glass jar 500ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Padma Glass', 'code' => 'SUP-001',
        'payment_terms_type' => 'net_days', 'payment_terms_days' => 30,
    ]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    // An approved order; received in full unless told otherwise.
    $this->order = function (string $quantity = '100', string $cost = '20.00', bool $receive = true, ?PaymentTerms $terms = null): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->manager, terms: $terms,
        );
        approveIfPending($order->approvalRequest, $this->manager);
        $order = $order->fresh(['lines', 'approvalRequest']);

        if ($receive) {
            app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => $quantity]], $this->manager);
        }

        return $order->fresh(['lines', 'approvalRequest', 'supplier']);
    };

    $this->billPayload = fn (PurchaseOrder $order, array $overrides = []) => array_merge([
        'purchase_order_id' => $order->id,
        'bill_number' => 'PG-1001',
        'bill_date' => '2026-08-20',
        'lines' => [[
            'purchase_order_line_id' => $order->lines->first()->id,
            'quantity' => (string) (float) $order->lines->first()->quantity_ordered,
            'unit_cost' => (string) $order->lines->first()->unit_cost,
        ]],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

// ── Due-date arithmetic ─────────────────────────────────────────────────────

it('works out the due date for each kind of terms, month ends included', function (string $type, ?int $days, string $billDate, string $due) {
    expect(PaymentTermType::from($type)->dueDate(Carbon::parse($billDate), $days)->toDateString())->toBe($due);
})->with([
    'immediate is the bill date' => ['immediate', null, '2026-08-03', '2026-08-03'],
    'advance is the bill date' => ['advance', null, '2026-08-03', '2026-08-03'],
    'net 0 is the bill date' => ['net_days', 0, '2026-08-03', '2026-08-03'],
    'net 30' => ['net_days', 30, '2026-08-03', '2026-09-02'],
    'net 30 from 31 Jan crosses February' => ['net_days', 30, '2026-01-31', '2026-03-02'],
    'net 30 across the year end' => ['net_days', 30, '2026-12-15', '2027-01-14'],
    'end of month + 0 mid month' => ['end_of_month', 0, '2026-01-15', '2026-01-31'],
    'end of month + 0 on the last day' => ['end_of_month', 0, '2026-01-31', '2026-01-31'],
    'end of month + 15 from 31 Jan is 15 Feb, never March' => ['end_of_month', 15, '2026-01-31', '2026-02-15'],
    'end of month in a leap February' => ['end_of_month', 0, '2028-02-01', '2028-02-29'],
    'end of month in a plain February' => ['end_of_month', 0, '2026-02-10', '2026-02-28'],
    'end of month + 30 from 30 Apr' => ['end_of_month', 30, '2026-04-30', '2026-05-30'],
    'end of month + 30 from December rolls the year' => ['end_of_month', 30, '2026-12-10', '2027-01-30'],
]);

it('reads only unambiguous free text as structured terms', function (string $text, ?string $type, ?int $days) {
    $terms = PaymentTerms::parse($text);

    expect($terms?->type->value)->toBe($type)
        ->and($terms?->days)->toBe($days);
})->with([
    ['COD', 'immediate', null],
    ['Cash on delivery', 'immediate', null],
    ['Advance', 'advance', null],
    ['100% advance', 'advance', null],
    ['Net 30', 'net_days', 30],
    ['net30', 'net_days', 30],
    ['30 days', 'net_days', 30],
    ['Net 15 days.', 'net_days', 15],
    ['EOM', 'end_of_month', 0],
    ['EOM + 15', 'end_of_month', 15],
    ['End of month + 30 days', 'end_of_month', 30],
    // Left alone: each could mean something else.
    ['30', null, null],
    ['cash', null, null],
    ['cheque after 2 weeks', null, null],
    ['Net 900', null, null],
    ['', null, null],
]);

it('copies only clearly-read old terms on the live data and never touches structured ones', function () {
    $migration = require base_path('app/Modules/Purchasing/database/migrations/2026_10_19_600100_existing_supplier_terms_that_read_clearly_become_structured.php');

    $make = fn (string $code, ?string $text, ?string $type = null, ?int $days = null) => DB::table('suppliers')->insertGetId([
        'business_id' => $this->business->id, 'code' => $code, 'name' => $code, 'payment_terms' => $text,
        'payment_terms_type' => $type, 'payment_terms_days' => $days, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $net = $make('S-NET', 'Net 30');
    $cod = $make('S-COD', 'COD');
    $vague = $make('S-VAGUE', 'cash');
    $kept = $make('S-KEPT', 'Net 30', 'advance');
    $none = $make('S-NONE', null);

    $migration->up();
    $migration->up();

    $row = fn (int $id) => DB::table('suppliers')->where('id', $id)->first(['payment_terms', 'payment_terms_type', 'payment_terms_days']);

    expect((array) $row($net))->toBe(['payment_terms' => 'Net 30', 'payment_terms_type' => 'net_days', 'payment_terms_days' => 30])
        ->and($row($cod)->payment_terms_type)->toBe('immediate')
        ->and($row($vague)->payment_terms_type)->toBeNull()
        ->and($row($vague)->payment_terms)->toBe('cash')
        ->and($row($kept)->payment_terms_type)->toBe('advance')
        ->and($row($none)->payment_terms_type)->toBeNull();
});

// ── The bill keeps its due date ─────────────────────────────────────────────

it('dates a bill from the supplier terms and keeps the date when the terms change later', function () {
    $order = ($this->order)();

    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order))->assertSessionHasNoErrors();

    $bill = SupplierBill::sole();
    expect($bill->due_date->toDateString())->toBe('2026-09-19')
        ->and($bill->due_date_source)->toBe('terms')
        ->and($bill->payment_terms_type)->toBe('net_days')
        ->and($bill->payment_terms_days)->toBe(30);

    // The supplier moves to end of month + 10: the recorded bill does not move.
    ($this->as)($this->manager)->put("/purchasing/suppliers/{$this->supplier->id}", [
        'code' => 'SUP-001', 'name' => 'Padma Glass', 'payment_terms_type' => 'end_of_month', 'payment_terms_days' => 10,
    ])->assertSessionHasNoErrors();

    expect($this->supplier->fresh()->payment_terms_type)->toBe('end_of_month')
        ->and($bill->fresh()->due_date->toDateString())->toBe('2026-09-19')
        ->and(PaymentTerms::forBill($bill->fresh())->label())->toBe('Net 30 days');

    ($this->as)($this->manager)->get("/purchasing/bills/{$bill->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bill.due_date', '2026-09-19')
            ->where('bill.terms.type', 'net_days')
            ->where('bill.due_date_source', 'terms')
            ->where('can_change_due_date', true));
});

it('uses the purchase order terms before the supplier terms', function () {
    $order = ($this->order)(terms: new PaymentTerms(PaymentTermType::NetDays, 7));

    ($this->as)($this->manager)->get("/purchasing/bills/new/{$order->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.terms.days', 7));

    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order))->assertSessionHasNoErrors();

    expect(SupplierBill::sole()->due_date->toDateString())->toBe('2026-08-27')
        ->and($order->fresh()->payment_terms_days)->toBe(7);
});

it('needs a reason when a bill is due on another date or other terms than agreed', function () {
    $order = ($this->order)();

    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order, ['due_date' => '2026-09-30']))
        ->assertSessionHasErrors('due_date_reason');
    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order, ['terms_type' => 'immediate']))
        ->assertSessionHasErrors('due_date_reason');
    expect(SupplierBill::count())->toBe(0);

    // Typing the date the terms give is not an override.
    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order, ['due_date' => '2026-09-19', 'bill_number' => 'PG-SAME']))
        ->assertSessionHasNoErrors();
    expect(SupplierBill::sole()->due_date_source)->toBe('terms');

    $other = ($this->order)();
    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($other, [
        'bill_number' => 'PG-LATE', 'due_date' => '2026-09-30', 'due_date_reason' => 'Supplier agreed 60 days on this lot',
        // Ruling P5: the same amount days after PG-SAME is asked about; it is another lot.
        'duplicate_reason' => 'A second lot of the same size',
    ]))->assertSessionHasNoErrors();

    $bill = SupplierBill::where('bill_number', 'PG-LATE')->sole();
    expect($bill->due_date->toDateString())->toBe('2026-09-30')
        ->and($bill->due_date_source)->toBe('override')
        ->and($bill->due_date_reason)->toBe('Supplier agreed 60 days on this lot');
});

it('keeps a typed due date as before when no terms are agreed, and leaves a blank one blank', function () {
    $this->supplier->update(['payment_terms_type' => null, 'payment_terms_days' => null]);
    $order = ($this->order)();
    $line = $order->lines->first();

    $typed = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: 'PG-T', billDate: '2026-08-20',
        lines: [['line' => $line, 'quantity' => '50', 'unitCost' => '20.00']],
        createdBy: $this->manager, purchaseOrder: $order, dueDate: '2026-08-25',
    );
    $blank = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: 'PG-B', billDate: '2026-08-20',
        lines: [['line' => $line->fresh(), 'quantity' => '50', 'unitCost' => '20.00']],
        createdBy: $this->manager, purchaseOrder: $order,
    );

    expect($typed->due_date->toDateString())->toBe('2026-08-25')
        ->and($typed->due_date_source)->toBe('typed')
        ->and($blank->due_date)->toBeNull()
        ->and($blank->due_date_source)->toBeNull();
});

it('changes a recorded due date only with a reason, in the bill history, and not for staff', function () {
    $order = ($this->order)();
    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($order));
    $bill = SupplierBill::sole();

    ($this->as)($this->staff)->post("/purchasing/bills/{$bill->id}/due-date", ['due_date' => '2026-09-15', 'reason' => 'x'])->assertForbidden();
    ($this->as)($this->manager)->post("/purchasing/bills/{$bill->id}/due-date", ['due_date' => '2026-09-15'])->assertSessionHasErrors('reason');
    ($this->as)($this->manager)->post("/purchasing/bills/{$bill->id}/due-date", ['due_date' => '2026-08-01', 'reason' => 'Typo'])
        ->assertSessionHasErrors('due_date');

    ($this->as)($this->manager)->post("/purchasing/bills/{$bill->id}/due-date", ['due_date' => '2026-09-15', 'reason' => 'Supplier gave two more weeks'])
        ->assertSessionHasNoErrors();

    $bill->refresh();
    $log = Activity::query()->where('subject_type', $bill->getMorphClass())->where('subject_id', $bill->id)->where('description', 'due date changed')->sole();

    expect($bill->due_date->toDateString())->toBe('2026-09-15')
        ->and($bill->due_date_source)->toBe('changed')
        ->and($bill->due_date_reason)->toBe('Supplier gave two more weeks')
        // The terms it was recorded on are still the ones it kept.
        ->and($bill->payment_terms_days)->toBe(30)
        ->and($log->causer_id)->toBe($this->manager->id)
        ->and($log->properties['old'])->toBe('2026-09-19')
        ->and($log->properties['new'])->toBe('2026-09-15');
});

it('stores the supplier terms and limit, and keeps no stray days on terms that have none', function () {
    ($this->as)($this->manager)->post('/purchasing/suppliers', ['code' => 'SUP-NEW', 'name' => 'Meghna Packaging', 'payment_terms_type' => 'net_days'])
        ->assertSessionHasErrors('payment_terms_days');

    ($this->as)($this->manager)->post('/purchasing/suppliers', [
        'code' => 'SUP-NEW', 'name' => 'Meghna Packaging', 'payment_terms_type' => 'immediate', 'payment_terms_days' => 45, 'credit_limit' => '50000',
    ])->assertSessionHasNoErrors();

    $supplier = Supplier::where('code', 'SUP-NEW')->sole();
    expect($supplier->payment_terms_type)->toBe('immediate')
        ->and($supplier->payment_terms_days)->toBeNull()
        ->and((string) $supplier->credit_limit)->toBe('50000.0000');
});

// ── The credit warning ──────────────────────────────────────────────────────

it('warns near the limit from 80 percent and over it above the limit, never on no limit', function (?int $limitPaisa, int $afterPaisa, string $level) {
    expect(SupplierCredit::level($limitPaisa === null ? null : Money::ofMinor($limitPaisa), Money::ofMinor($afterPaisa)))->toBe($level);
})->with([
    'no limit' => [null, 999_999_00, 'none'],
    'well under' => [100_000_00, 50_000_00, 'ok'],
    'a paisa under 80%' => [100_000_00, 79_999_99, 'ok'],
    'exactly 80%' => [100_000_00, 80_000_00, 'near'],
    'exactly the limit' => [100_000_00, 100_000_00, 'near'],
    'a paisa over' => [100_000_00, 100_000_01, 'over'],
]);

it('shows billed, received-not-billed, open orders and advances apart, and warns without blocking', function () {
    $this->supplier->update(['credit_limit' => '5000']);

    // Billed: 100 x 20 = 2,000.
    $billed = ($this->order)();
    ($this->as)($this->manager)->post('/purchasing/bills', ($this->billPayload)($billed))->assertSessionHasNoErrors();
    // Received, not billed: 50 x 20 = 1,000.
    ($this->order)('50');
    // Ordered, not received: 40 x 20 = 800.
    ($this->order)('40', '20.00', false);
    // An advance of 300 is shown and never taken off.
    app(RecordSupplierAdvance::class)->pay(supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-10', amount: '300', createdBy: $this->manager);

    $credit = SupplierCredit::for($this->supplier->fresh());

    expect($credit['billed'])->toBe('2000.00')
        ->and($credit['received_not_billed'])->toBe('1000.00')
        ->and($credit['open_orders'])->toBe('800.00')
        ->and($credit['advances'])->toBe('300.00')
        ->and($credit['exposure'])->toBe('3800.00')
        ->and($credit['after_less_advances'])->toBe('3500.00')
        ->and($credit['level'])->toBe('ok');

    // A new order of 1,500 would take it to 5,300: over by 300, still only a warning.
    ($this->as)($this->manager)->getJson("/purchasing/suppliers/{$this->supplier->id}/credit?adding=1500")
        ->assertOk()
        ->assertJson(['after' => '5300.00', 'level' => 'over', 'headroom' => '-300.00', 'open_orders' => '800.00']);

    ($this->as)($this->manager)->post('/purchasing/purchase-orders', [
        'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => '2026-08-20',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '75', 'unit_cost' => '20.00']],
    ])->assertSessionHasNoErrors();
    expect(PurchaseOrder::count())->toBe(4);
});

it('puts the credit warning on the bill form and the supplier page, readable by staff', function () {
    $order = ($this->order)();

    ($this->as)($this->manager)->get("/purchasing/bills/new/{$order->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('credit.received_not_billed', '2000.00')->where('credit.level', 'none'));

    ($this->as)($this->staff)->get("/purchasing/suppliers/{$this->supplier->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('credit.terms.type', 'net_days'));
    ($this->as)($this->staff)->getJson("/purchasing/suppliers/{$this->supplier->id}/credit")->assertOk();
});

// ── Ageing and the bills-due list read the stored due date ──────────────────

it('ages a bill from its due date, and from its bill date only when it has none', function () {
    $order = ($this->order)();
    $line = $order->lines->first();
    $record = fn (string $number, string $date, ?string $due) => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: $number, billDate: $date,
        lines: [['line' => $line->fresh(), 'quantity' => '25', 'unitCost' => '20.00']],
        createdBy: $this->manager, purchaseOrder: $order, dueDate: $due,
        dueDateReason: $due === null ? null : 'test',
    );

    // Dated 1 Jul on net 30: due 31 Jul, 20 days late on 20 Aug.
    $record('A-NET', '2026-07-01', null);
    // Dated 1 Jul, due 15 Sep by agreement: not late at all.
    $record('A-LATER', '2026-07-01', '2026-09-15');

    $this->supplier->update(['payment_terms_type' => null, 'payment_terms_days' => null]);
    // No terms, no due date: counted from 1 Jun, 80 days.
    $record('A-NONE', '2026-06-01', null);

    $documents = Ageing::payables(Carbon::today())->rows->firstWhere('partyId', $this->supplier->id)->documents->keyBy('number');

    expect($documents['A-NET']->daysOverdue)->toBe(20)
        ->and($documents['A-NET']->bucket())->toBe(AgeingBucket::Days1To30)
        ->and($documents['A-LATER']->daysOverdue)->toBe(-26)
        ->and($documents['A-LATER']->bucket())->toBe(AgeingBucket::Current)
        ->and($documents['A-NONE']->daysOverdue)->toBe(80)
        ->and($documents['A-NONE']->usesFallbackDate())->toBeTrue();

    // The bills-due list: overdue worst first, then due soon; 15 Sep is outside 7 days.
    ($this->as)($this->staff)->get('/purchasing/bills-due')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/bills/due')
            ->where('groups.overdue.count', 2)
            ->where('rows.0.bill_number', 'A-NONE')
            ->where('rows.0.no_due_date', true)
            ->where('rows.1.bill_number', 'A-NET')
            ->where('rows.1.days', -20)
            ->where('rows.1.terms.type', 'net_days')
            ->has('rows', 2));

    ($this->as)($this->staff)->get('/purchasing/bills-due?days=30')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('groups.soon.count', 1)->where('rows.2.bill_number', 'A-LATER'));
});
