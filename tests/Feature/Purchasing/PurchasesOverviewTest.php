<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Purchases overview (module redesign, 15 October 2026): where purchases
 * stand step by step, what needs somebody, what is owed by age, what was
 * spent, the standing costs coming up, on one page, every number a link.
 *
 * ⭐ Every number is read from the rows the lists read (PurchasesPulse,
 * Ageing::payables, StandingCosts), never re-derived, and the payables card
 * shows only with accounting.view, the gate on the report it summarises.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // The ledger reader: accounting.view, which the Owner holds and Staff does not.
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Role::findByName('Owner')->givePermissionTo('accounting.view');

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->staff = ($this->user)('Staff');
    // Reads purchasing, holds nothing else: the role the page must still render for.
    Role::create(['name' => 'Reader', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('purchasing.reports.view');
    $this->reader = ($this->user)('Reader');
    // Holds no purchasing permission at all.
    Role::create(['name' => 'Nobody', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    $this->nobody = ($this->user)('Nobody');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->hilsa = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'RM-FISH-HILSA',
        'name' => 'Hilsa, whole',
        'type' => ProductType::RawMaterial,
        'tracks_expiry' => true,
        'shelf_life_warning_days' => 30,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Barisal Fish Traders']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // ⚠ An Owner self-approves on creation (CreatePurchaseOrder), so the order
    // left waiting is raised by Staff, who may create and not approve.
    $this->order = function (bool $approve = true): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->hilsa, 'quantity' => '10', 'unit_cost' => '900']],
            createdBy: $approve ? $this->owner : $this->staff,
        );
        if ($approve) {
            approveIfPending($order->approvalRequest, $this->owner);
        }

        return $order->fresh(['lines', 'approvalRequest']);
    };

    $this->receive = function (PurchaseOrder $order, string $on): PurchaseOrder {
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle(
            $order,
            [['line' => $line, 'quantity' => (string) $line->quantity_ordered, 'batch_number' => 'HL-'.$order->id, 'expiry_date' => '2026-10-29']],
            $this->owner,
            null,
            'CH-'.$order->id,
            $on,
            'Entered from the challan',
        );

        return $order->fresh(['lines']);
    };

    $this->bill = fn (PurchaseOrder $order, string $number, string $date, string $due): SupplierBill => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: $number,
        billDate: Carbon::parse($date),
        lines: [['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => '900']],
        createdBy: $this->owner,
        purchaseOrder: $order,
        dueDate: Carbon::parse($due),
    );

    $this->page = fn (User $as, string $url = '/purchasing/overview') => $this->actingAs($as)
        ->withSession(['current_business_id' => $this->business->id])
        ->get($url);

    $this->props = fn (User $as, string $url = '/purchasing/overview') => ($this->page)($as, $url)->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The fixture the design was drawn for: one order at each step of the chain,
 * one bill 38 days overdue, one due in three days and part paid, an expense
 * this month and one last month, a standing cost due in three days.
 */
function overviewChain(): array
{
    $t = test();

    $pending = ($t->order)(approve: false);
    $awaitingGoods = ($t->order)();
    $toBill = ($t->receive)(($t->order)(), '2026-10-03');

    $overdueOrder = ($t->receive)(($t->order)(), '2026-08-30');
    $overdue = ($t->bill)($overdueOrder, 'BFT-1187', '2026-09-01', '2026-09-07');

    $dueOrder = ($t->receive)(($t->order)(), '2026-10-09');
    $due = ($t->bill)($dueOrder, 'BFT-1203', '2026-10-10', '2026-10-18');
    app(RecordSupplierPayment::class)->handle(
        supplier: $t->supplier,
        moneyAccount: $t->bank,
        paidOn: '2026-10-05',
        allocations: [['bill' => $due, 'amount' => '4000']],
        createdBy: $t->owner,
    );

    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $t->bank, spentOn: '2026-10-02',
        lines: [['account' => Account::bySlug('fuel_and_conveyance'), 'amount' => '2500', 'description' => 'Delivery fuel']],
        createdBy: $t->owner, paidTo: 'Pump', checkBalance: false,
    );
    app(RecordExpenseVoucher::class)->handle(
        moneyAccount: $t->bank, spentOn: '2026-09-10',
        lines: [['account' => Account::where('code', '6051')->first(), 'amount' => '18000', 'description' => 'September rent']],
        createdBy: $t->owner, paidTo: 'Landlord', checkBalance: false,
    );

    RecurringExpense::create([
        'business_id' => $t->business->id,
        'name' => 'Web hosting',
        'expense_account_id' => Account::where('code', '6051')->first()->id,
        'money_account_id' => $t->bank->id,
        'amount' => 3800,
        'frequency' => RecurringFrequency::Monthly,
        'day_of_month' => 18,
        'start_date' => '2026-10-01',
        'reference_prefix' => 'HOST',
        'status' => RecurringExpenseStatus::Active,
    ]);

    return compact('pending', 'awaitingGoods', 'toBill', 'overdueOrder', 'overdue', 'dueOrder', 'due');
}

// ── Gates ───────────────────────────────────────────────────────────────────

it('opens for anyone who may read purchasing, and for nobody else', function () {
    ($this->page)($this->nobody)->assertForbidden();

    foreach ([$this->owner, $this->staff, $this->reader] as $user) {
        ($this->page)($user)->assertOk();
    }
});

it('hands the payables card only to a ledger reader, as null and never as zeros', function () {
    overviewChain();

    $owner = ($this->props)($this->owner);
    expect($owner['can']['accounting'])->toBeTrue()
        ->and($owner['payables'])->not->toBeNull()
        ->and($owner['chain']['owed'])->not->toBeNull();

    $reader = ($this->props)($this->reader);
    expect($reader['can']['accounting'])->toBeFalse()
        ->and($reader['payables'])->toBeNull()
        ->and($reader['chain']['owed'])->toBeNull()
        // The rest of the page still stands for a plain reader.
        ->and($reader['chain']['bills_open']['count'])->toBe(2);
});

it('shares canManageSuppliers from purchasing.suppliers.manage with every page', function () {
    expect(($this->props)($this->owner)['canManageSuppliers'])->toBeTrue()
        ->and(($this->props)($this->staff)['canManageSuppliers'])->toBeFalse();
});

// ── The chain ───────────────────────────────────────────────────────────────

it('counts each step of the chain from the same rows its list shows', function () {
    $f = overviewChain();
    $chain = ($this->props)($this->owner)['chain'];

    expect($chain['awaiting_approval'])->toBe([
        'count' => 1, 'ordered' => '9,000.00', 'numbers' => [$f['pending']->po_number], 'url' => '/purchasing/purchase-orders?approval=pending',
    ])->and($chain['awaiting_goods'])->toBe(['count' => 1, 'partly' => 0, 'url' => '/purchasing/purchase-orders?stage=awaiting_goods'])
        ->and($chain['received_not_billed'])->toBe([
            'count' => 1, 'oldest_days' => 12, 'numbers' => [$f['toBill']->po_number], 'url' => '/purchasing/purchase-orders?stage=to_bill',
        ])
        ->and($chain['bills_open'])->toBe(['count' => 2, 'amount' => '14,000.00', 'due_this_week' => 1, 'url' => '/purchasing/bills?status=open'])
        ->and($chain['owed'])->toBe(['total' => '14,000.00', 'overdue' => '9,000.00', 'url' => '/accounting/payables-ageing'])
        ->and($chain['open_orders'])->toBe(2)
        ->and($chain['suppliers_open'])->toBe(1);
});

it('opens each chain step on the purchase order list, pre-filtered', function () {
    $f = overviewChain();

    $numbers = fn (string $query) => collect(($this->props)($this->owner, '/purchasing/purchase-orders'.$query)['orders']['data'])->pluck('po_number')->all();

    expect($numbers('?approval=pending'))->toBe([$f['pending']->po_number])
        ->and($numbers('?stage=awaiting_goods'))->toBe([$f['awaitingGoods']->po_number])
        ->and($numbers('?stage=to_bill'))->toBe([$f['toBill']->po_number]);
});

// ── Needs you ───────────────────────────────────────────────────────────────

it('lists what needs somebody, loudest first, each row a link', function () {
    $f = overviewChain();
    $needs = collect(($this->props)($this->owner)['needs'])->keyBy('key');

    expect($needs->map(fn (array $row) => [$row['count'], $row['tone'], $row['url']])->all())->toBe([
        'overdue_bills' => [1, 'danger', '/purchasing/bills?status=overdue'],
        'to_approve' => [1, 'warning', '/purchasing/purchase-orders?approval=pending'],
        'due_week' => [1, 'warning', '/purchasing/bills?status=due_week'],
        'standing_due' => [1, 'info', '/purchasing/recurring-expenses'],
        'to_bill' => [1, 'ok', '/purchasing/purchase-orders?stage=to_bill'],
    ])->and($needs['overdue_bills']['title'])->toBe('Bill overdue 38 days')
        ->and($needs['overdue_bills']['hint'])->toBe('Tk 9,000.00 past the due date')
        ->and($needs['to_approve']['hint'])->toBe($f['pending']->po_number);
});

// ── We owe suppliers ────────────────────────────────────────────────────────

it('summarises the payables ageing without re-deriving it', function () {
    overviewChain();
    $payables = ($this->props)($this->owner)['payables'];

    expect($payables['total'])->toBe('14,000.00')
        ->and($payables['overdue'])->toBe('9,000.00')
        ->and($payables['ties_out'])->toBeTrue()
        ->and(collect($payables['buckets'])->pluck('amount', 'key')->all())->toBe([
            'current' => '5,000.00', 'days_1_30' => '0.00', 'days_31_60' => '9,000.00', 'days_61_90' => '0.00', 'days_90_plus' => '0.00',
        ])
        ->and(collect($payables['buckets'])->pluck('share', 'key')->all())->toBe([
            'current' => 36, 'days_1_30' => 0, 'days_31_60' => 64, 'days_61_90' => 0, 'days_90_plus' => 0,
        ])
        ->and($payables['top'])->toBe([[
            'id' => $this->supplier->id, 'name' => 'Barisal Fish Traders', 'amount' => '14,000.00', 'overdue' => true, 'url' => '/purchasing/suppliers/'.$this->supplier->id,
        ]]);
});

// ── Running and standing costs ──────────────────────────────────────────────

it('sums the expenses for each period chip, reversed vouchers left out', function () {
    overviewChain();
    $spend = ($this->props)($this->owner)['spend'];

    expect($spend['month']['label'])->toBe('October 2026, to date')
        ->and($spend['month']['total'])->toBe('2,500.00')
        ->and($spend['month']['count'])->toBe(1)
        ->and($spend['month']['heads'][0]['share'])->toBe(100)
        ->and($spend['month']['paid_from'])->toBe([['name' => 'BRAC Bank', 'percent' => 100]])
        ->and($spend['last']['label'])->toBe('September 2026')
        ->and($spend['last']['total'])->toBe('18,000.00')
        ->and($spend['quarter']['label'])->toBe('Oct to Dec 2026, to date')
        ->and($spend['quarter']['total'])->toBe('2,500.00');
});

it('reads the standing costs from the recurring screen\'s own list', function () {
    overviewChain();
    $standing = ($this->props)($this->owner)['standing'];

    expect($standing['active'])->toBe(1)
        ->and($standing['monthly'])->toBe('3,800.00')
        ->and($standing['annual'])->toBe('45,600.00')
        ->and($standing['due_week'])->toBe(1)
        ->and($standing['due'])->toHaveCount(1)
        ->and($standing['due'][0])->toMatchArray(['name' => 'Web hosting', 'due_on' => '2026-10-18', 'days_away' => 3, 'expected_amount' => '3,800.00']);
});

// ── Deliveries and suppliers ────────────────────────────────────────────────

it('shows the latest deliveries with the batch and expiry each created', function () {
    $f = overviewChain();
    $deliveries = ($this->props)($this->owner)['deliveries'];

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries[0])->toMatchArray([
            'received_on' => '2026-10-09', 'item' => 'Hilsa, whole', 'unit' => 'kg', 'po_number' => $f['dueOrder']->po_number,
            'batch' => 'HL-'.$f['dueOrder']->id, 'expires_on' => '2026-10-29', 'days_left' => 14,
            'url' => '/purchasing/purchase-orders/'.$f['dueOrder']->id,
        ]);

    $suppliers = ($this->props)($this->owner)['suppliers'];
    expect($suppliers['active'])->toBe(1)
        ->and($suppliers['with_balance'])->toBe(1)
        ->and($suppliers['certificate_expiring'])->toBe(0);
});

it('renders an empty business without a number out of place', function () {
    $props = ($this->props)($this->owner);

    expect($props['chain']['awaiting_approval']['count'])->toBe(0)
        ->and($props['chain']['owed']['total'])->toBe('0.00')
        ->and($props['needs'])->toBe([])
        ->and($props['payables']['top'])->toBe([])
        ->and($props['spend']['month']['heads'])->toBe([])
        ->and($props['standing']['due'])->toBe([])
        ->and($props['deliveries'])->toBe([]);
});

// ── Bills & payments: the money strip and the chips ─────────────────────────

it('puts the same money strip on Bills, Payments and Credit notes', function () {
    overviewChain();

    $expected = [
        'open' => ['count' => 2, 'amount' => '14,000.00', 'url' => '/purchasing/bills?status=open'],
        'overdue' => ['count' => 1, 'amount' => '9,000.00', 'oldest_days' => 38, 'url' => '/purchasing/bills?status=overdue'],
        'due_week' => ['count' => 1, 'amount' => '5,000.00', 'url' => '/purchasing/bills?status=due_week'],
    ];

    foreach (['/purchasing/bills', '/purchasing/payments', '/purchasing/credit-notes'] as $url) {
        $strip = ($this->props)($this->owner, $url)['strip'];

        expect($strip)->toMatchArray($expected)
            ->and($strip['paid_month'])->toMatchArray(['count' => 1, 'amount' => '4,000.00', 'label' => 'Paid in October', 'by_account' => [['name' => 'BRAC Bank', 'count' => 1]]])
            ->and($strip['to_bill']['count'])->toBe(1);
    }
});

it('filters bills by the chips, each counted the way the strip counts', function () {
    $f = overviewChain();

    $bills = fn (string $query = '') => ($this->props)($this->owner, '/purchasing/bills'.$query);

    expect($bills()['counts'])->toBe(['all' => 2, 'open' => 2, 'overdue' => 1, 'due_week' => 1, 'paid' => 0, 'reversed' => 0]);

    $rows = collect($bills()['bills']['data'])->keyBy('bill_number');
    expect($rows['BFT-1187'])->toMatchArray(['state' => 'overdue', 'overdue_days' => 38, 'outstanding' => '9000.00', 'purchase_order' => ['id' => $f['overdueOrder']->id, 'po_number' => $f['overdueOrder']->po_number]])
        ->and($rows['BFT-1203'])->toMatchArray(['state' => 'part_paid', 'overdue_days' => 0, 'due_in_days' => 3, 'outstanding' => '5000.00']);

    expect(collect($bills('?status=overdue')['bills']['data'])->pluck('bill_number')->all())->toBe(['BFT-1187'])
        ->and(collect($bills('?status=due_week')['bills']['data'])->pluck('bill_number')->all())->toBe(['BFT-1203'])
        ->and($bills('?status=paid')['bills']['data'])->toBe([])
        ->and($bills()['can_pay'])->toBeTrue();
});

// ── The purchase order page ─────────────────────────────────────────────────

it('tells the Receive goods step which products track expiry and when to warn', function () {
    $order = ($this->order)();

    ($this->page)($this->owner, '/purchasing/purchase-orders/'.$order->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.product.tracks_expiry', true)
            ->where('order.lines.0.product.shelf_life_warning_days', 30)
            ->where('can_receive', true)
        );
});
