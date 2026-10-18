<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\QuickPurchaseApprovals;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Buy and receive on one screen (supplier finance wave C, ruling P4).
 *
 * The orchestrator must post exactly what the four separate screens post,
 * save all or nothing, save once per request key, and never be a way round a
 * step's own permission or approval.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->supplier = $this->d->supplier;

    // ⚠ Purchases are dated "today" (17 Oct 2026, CorrectionWorld's clock).
    $this->buy = function (array $overrides = [], ?User $by = null): QuickPurchase {
        $args = $overrides + [
            'requestKey' => (string) Str::uuid(),
            'supplier' => $this->supplier,
            'warehouse' => $this->d->warehouse,
            'purchasedOn' => '2026-10-17',
            'lines' => [['product' => $this->d->salt, 'quantity' => '500', 'unit_cost' => '20.00']],
            'billNumber' => 'CM-501',
            'by' => $by ?? $this->w->owner,
        ];

        return app(RecordQuickPurchase::class)->handle(...$args);
    };

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    // One journal's lines as account | signed paisa | party kind, sorted.
    $this->shape = fn ($source) => Journal::query()
        ->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
        ->with('lines')->get()
        ->flatMap(fn (Journal $journal) => $journal->lines->map(fn ($line) => $line->account_id.'|'.$line->amount_minor.'|'.($line->party_type === null ? '-' : 'supplier')))
        ->sort()->values()->all();

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();
});

afterEach(fn () => Carbon::setTestNow());

it('posts exactly the journals the four separate screens post', function () {
    // The separate way, for a second supplier: order, receipt, bill, payment.
    $other = Supplier::create(['business_id' => $this->w->business->id, 'name' => 'Khulna Salt', 'code' => 'SUP-002']);
    $order = app(CreatePurchaseOrder::class)->handle($other, $this->d->warehouse, [['product' => $this->d->salt, 'quantity' => '500', 'unit_cost' => '20.00']], $this->w->owner, orderDate: '2026-10-17');
    approveIfPending($order->approvalRequest, $this->w->owner);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $receipt = app(RecordGoodsReceipt::class)->handle($order, $order->lines->map(fn ($l) => ['line' => $l, 'quantity' => '500'])->all(), $this->w->owner, receivedOn: '2026-10-17');
    $bill = app(RecordSupplierBill::class)->handle($other, 'CM-501', '2026-10-17', $order->fresh('lines')->lines->map(fn ($l) => ['line' => $l, 'quantity' => '500', 'unitCost' => '20.00'])->all(), $this->w->owner, $order);
    $payment = app(RecordSupplierPayment::class)->handle($other, $this->w->bank, '2026-10-17', [['bill' => $bill, 'amount' => '4000']], $this->w->owner);

    // The quick way, for the first supplier, the same goods and payment.
    $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '4000']]);

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($quick->wasRecentlyCreated)->toBeTrue()
        ->and(($this->shape)($quick->goodsReceipt))->toBe(($this->shape)($receipt))
        ->and(($this->shape)($quick->bill))->toBe(($this->shape)($bill))
        ->and(($this->shape)($quick->payment))->toBe(($this->shape)($payment))
        ->and(($this->shape)($quick->goodsReceipt))->not->toBeEmpty();

    // Every document has its own normal number and the two orders differ.
    expect($quick->purchaseOrder->po_number)->toStartWith('PO-')
        ->and($quick->purchaseOrder->po_number)->not->toBe($order->po_number)
        ->and($quick->bill->bill_number)->toBe('CM-501')
        ->and($quick->bill->outstanding()->minor)->toBe(600000)
        ->and($quick->goods_paisa)->toBe(1000000)
        ->and($quick->paid_paisa)->toBe(400000);

    // Two batches at the same cost; nothing to GRNI is left for either supplier.
    expect(StockBatch::query()->where('product_id', $this->d->salt->id)->pluck('unit_cost')->map(fn ($c) => (string) $c)->unique()->values()->all())->toBe(['20.0000'])
        ->and(($this->balance)('goods_received_not_invoiced'))->toBe(0);

    ($this->booksTie)();
});

it('records no payment when none was made: the bill stays owed in full', function () {
    $quick = ($this->buy)();

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($quick->supplier_payment_id)->toBeNull()
        ->and(SupplierPayment::count())->toBe(0)
        ->and($quick->bill->outstanding()->minor)->toBe(1000000)
        ->and(($this->balance)('cash_at_bank'))->toBe(0)
        ->and(($this->balance)('accounts_payable'))->toBe(-1000000);

    ($this->booksTie)();
});

it('takes a part payment and leaves the rest owed on the bill', function () {
    $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '2500.50', 'reference' => 'bKash 8XY']]);

    expect($quick->payment->reference)->toBe('bKash 8XY')
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(749950)
        ->and(($this->balance)('cash_at_bank'))->toBe(-250050)
        ->and(SupplierAdvance::count())->toBe(0);

    ($this->booksTie)();
});

it('keeps an overpayment as an advance only when the payer confirmed it', function () {
    // Unconfirmed: refused under the amount box, and nothing at all is saved.
    expect(fn () => ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '10500']]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('payment_amount'));

    expect(PurchaseOrder::count())->toBe(0)->and(QuickPurchase::count())->toBe(0);

    $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '10500', 'excess_to_advance' => true]]);

    $advance = SupplierAdvance::query()->sole();
    expect($quick->bill->fresh()->outstanding()->minor)->toBe(0)
        ->and($advance->supplier_payment_id)->toBe($quick->supplier_payment_id)
        ->and($advance->money()->minor)->toBe(50000)
        ->and(($this->balance)('cash_at_bank'))->toBe(-1050000);

    ($this->booksTie)();
});

it('adds extra costs through the landed-cost engine and pays the supplier for goods and carriage together', function () {
    $quick = ($this->buy)([
        'extraCosts' => [
            // Owed to the supplier: a bill of its own (LC number), into the stock cost.
            ['cost_type' => 'freight', 'amount' => '500', 'payee' => 'supplier'],
            // Paid from cash on the day: unloading labour, into the stock cost too.
            ['cost_type' => 'labour', 'amount' => '250', 'payee' => 'money', 'money_account_id' => $this->w->cash->id],
        ],
        'payment' => ['money_account' => $this->w->bank, 'amount' => '10500'],
    ]);

    $landed = LandedCost::query()->sole();
    $batch = StockBatch::query()->where('product_id', $this->d->salt->id)->sole();
    $carriageBill = SupplierBill::query()->where('landed_cost_id', $landed->id)->sole();

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($quick->landed_cost_id)->toBe($landed->id)
        ->and($landed->status)->toBe(LandedCostStatus::Posted)
        ->and($quick->extra_costs_paisa)->toBe(75000)
        // 10,000 + 750 over 500 kg.
        ->and((string) $batch->unit_cost)->toBe('21.5000')
        ->and(($this->balance)('stock_on_hand'))->toBe(1075000)
        // Goods bill and carriage bill both settled by the one payment.
        ->and($quick->bill->fresh()->outstanding()->minor)->toBe(0)
        ->and($carriageBill->fresh()->outstanding()->minor)->toBe(0)
        ->and($quick->payment->allocations()->count())->toBe(2)
        ->and(($this->balance)('cash_in_hand'))->toBe(-25000)
        ->and(($this->balance)('cash_at_bank'))->toBe(-1050000)
        ->and(SupplierAdvance::count())->toBe(0);

    ($this->booksTie)();
});

it('takes a trade discount printed on the bill off the cost of the goods', function () {
    $quick = ($this->buy)(['tradeDiscount' => ['value' => '300', 'reference' => 'CM-501']]);

    expect($quick->bill->fresh()->outstanding()->minor)->toBe(970000)
        ->and((string) StockBatch::query()->where('product_id', $this->d->salt->id)->sole()->unit_cost)->toBe('19.4000');

    ($this->booksTie)();
});

it('saves nothing at all when any step is refused', function () {
    $jar = Product::create([
        'business_id' => $this->w->business->id, 'unit_of_measure_id' => $this->d->salt->unit_of_measure_id,
        'sku' => 'GHEE', 'name' => 'Ghee', 'type' => $this->d->salt->type, 'tracks_expiry' => true,
    ]);

    $counts = fn () => [PurchaseOrder::count(), GoodsReceipt::count(), StockMovement::count(), StockBatch::count(), SupplierBill::count(), SupplierPayment::count(), LandedCost::count(), Journal::count(), QuickPurchase::count()];
    $before = $counts();

    // The receipt refuses a product that tracks expiry without its expiry...
    expect(fn () => ($this->buy)(['lines' => [
        ['product' => $this->d->salt, 'quantity' => '10', 'unit_cost' => '20'],
        ['product' => $jar, 'quantity' => '5', 'unit_cost' => '400'],
    ]]))->toThrow(InvalidArgumentException::class);

    // ...the landed cost refuses a money account that is not active...
    $this->w->cash->forceFill(['is_active' => false])->save();
    expect(fn () => ($this->buy)(['extraCosts' => [['cost_type' => 'labour', 'amount' => '100', 'payee' => 'money', 'money_account_id' => $this->w->cash->id]]]))
        ->toThrow(ValidationException::class);

    // ...and the payment, the last step, refuses an inactive account too.
    expect(fn () => ($this->buy)(['payment' => ['money_account' => $this->w->cash->fresh(), 'amount' => '100']]))
        ->toThrow(ValidationException::class);

    expect($counts())->toBe($before);

    // The order number was not used up: the next order takes the first one.
    $quick = ($this->buy)();
    expect($quick->purchaseOrder->po_number)->toBe('PO-000001');
});

it('saves once per request key, however often it is sent', function () {
    $key = (string) Str::uuid();
    $first = ($this->buy)(['requestKey' => $key, 'payment' => ['money_account' => $this->w->bank, 'amount' => '1000']]);
    $again = ($this->buy)(['requestKey' => $key, 'payment' => ['money_account' => $this->w->bank, 'amount' => '1000']]);

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and(PurchaseOrder::count())->toBe(1)
        ->and(GoodsReceipt::count())->toBe(1)
        ->and(StockMovement::count())->toBe(1)
        ->and(SupplierBill::count())->toBe(1)
        ->and(SupplierPayment::count())->toBe(1)
        ->and(($this->balance)('cash_at_bank'))->toBe(-100000);
});

it('refuses anyone who lacks a step\'s own permission, before writing anything', function () {
    // Finance does not hold the screen (decision 19 Oct 2026: Finance pays,
    // it does not also order and receive), nor any order or bill right.
    expect(fn () => ($this->buy)([], $this->w->financeA))
        ->toThrow(AuthorizationException::class, 'use Buy and receive');

    // A role with every purchasing right except paying cannot pay through here.
    $role = Role::create(['name' => 'Buyer', 'guard_name' => 'web', 'business_id' => $this->w->business->id]);
    $role->givePermissionTo(['purchasing.quick.purchase', 'purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record', 'purchasing.bill.record']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $buyer = $this->w->member('Buyer', 'Buyer');

    expect(fn () => ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '100']], $buyer))
        ->toThrow(AuthorizationException::class, 'pay a supplier');
    expect(fn () => ($this->buy)(['extraCosts' => [['cost_type' => 'freight', 'amount' => '100', 'payee' => 'supplier']]], $buyer))
        ->toThrow(AuthorizationException::class, 'enter landed costs');

    expect(PurchaseOrder::count())->toBe(0)->and(QuickPurchase::count())->toBe(0);

    // Without the payment the same person buys and receives.
    expect(($this->buy)([], $buyer)->status)->toBe(QuickPurchase::COMPLETED);
});

it('saves only the order when the person may not approve orders, and receives nothing', function () {
    $role = Role::create(['name' => 'Clerk', 'guard_name' => 'web', 'business_id' => $this->w->business->id]);
    $role->givePermissionTo(['purchasing.quick.purchase', 'purchasing.po.create', 'purchasing.receipt.record', 'purchasing.bill.record']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $quick = ($this->buy)([], $this->w->member('Clerk', 'Clerk'));

    expect($quick->status)->toBe(QuickPurchase::AWAITING_ORDER_APPROVAL)
        ->and($quick->purchaseOrder->approvalRequest->isPending())->toBeTrue()
        ->and($quick->held_reason)->toContain($quick->purchaseOrder->po_number)
        ->and(GoodsReceipt::count())->toBe(0)
        ->and(SupplierBill::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('stops cleanly after the receipt when the bill waits for approval, and pays nothing', function () {
    // ⭐ The P7 integration point, stubbed: the bill action RETURNS a bill that waits.
    app()->instance(QuickPurchaseApprovals::class, new class extends QuickPurchaseApprovals
    {
        public function billHold(SupplierBill $bill): ?string
        {
            return "Bill {$bill->bill_number} waits for a second person.";
        }
    });

    $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '1000']]);

    expect($quick->status)->toBe(QuickPurchase::AWAITING_BILL_APPROVAL)
        ->and($quick->held_reason)->toBe('Bill CM-501 waits for a second person.')
        ->and($quick->goods_receipt_id)->not->toBeNull()
        ->and($quick->supplier_bill_id)->not->toBeNull()
        ->and(SupplierPayment::count())->toBe(0)
        ->and(StockBatch::count())->toBe(1);
});

it('keeps the receipt and takes back only the bill when the bill action refuses for approval', function () {
    // ⭐ The other P7 shape: the bill action THROWS "needs approval".
    $needsApproval = new class('Needs a second person: Tk 2 lakh or more.') extends RuntimeException {};
    app()->instance(RecordSupplierBill::class, new class($needsApproval) extends RecordSupplierBill
    {
        public function __construct(private RuntimeException $refusal) {}

        public function handle(...$args): SupplierBill
        {
            SupplierBill::create(['supplier_id' => $args['supplier']->id, 'bill_number' => 'HALF-SAVED', 'bill_date' => '2026-10-17', 'total' => 1, 'created_by' => $args['createdBy']->id]);

            throw $this->refusal;
        }
    });
    app()->instance(QuickPurchaseApprovals::class, new class($needsApproval) extends QuickPurchaseApprovals
    {
        public function __construct(private RuntimeException $refusal) {}

        public function holdFrom(Throwable $e): ?string
        {
            return $e === $this->refusal ? $e->getMessage() : null;
        }
    });

    $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '1000']]);

    expect($quick->status)->toBe(QuickPurchase::AWAITING_BILL_APPROVAL)
        ->and($quick->held_reason)->toContain('Tk 2 lakh')
        ->and($quick->goods_receipt_id)->not->toBeNull()
        ->and($quick->supplier_bill_id)->toBeNull()
        // The bill's half-made row went back with its savepoint.
        ->and(SupplierBill::count())->toBe(0)
        ->and(SupplierPayment::count())->toBe(0)
        ->and(StockBatch::count())->toBe(1)
        ->and(($this->balance)('goods_received_not_invoiced'))->toBe(-1000000);
});

it('asks P7 about the whole commitment, goods plus extra costs, not what is paid today', function () {
    app()->instance(QuickPurchaseApprovals::class, new class extends QuickPurchaseApprovals
    {
        public ?Money $seen = null;

        public function commitmentHold(Money $commitment, Supplier $supplier, User $by, ?Money $discount = null, ?Money $goods = null): ?string
        {
            $this->seen = $commitment;

            return parent::commitmentHold($commitment, $supplier, $by, $discount, $goods);
        }
    });

    ($this->buy)([
        'extraCosts' => [['cost_type' => 'freight', 'amount' => '500', 'payee' => 'supplier']],
        'payment' => ['money_account' => $this->w->bank, 'amount' => '100'],
    ]);

    expect(app(QuickPurchaseApprovals::class)->seen->minor)->toBe(1050000);
});

it('refuses a bill number the supplier already used under its own box, before making anything', function () {
    ($this->buy)();

    expect(fn () => ($this->buy)())
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('bill_number'));

    expect(PurchaseOrder::count())->toBe(1);
});

// ── The screen ─────────────────────────────────────────────────────────────

it('is reached from Purchases and saves through the screen, once per key', function () {
    $this->actingAs($this->w->owner)->get('/purchasing/buy-and-receive/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('purchasing/buy-and-receive/create')
            ->has('products')->has('moneyAccounts')->has('costTypes')
            ->where('rights.pay', true)->where('missing', []));

    $form = [
        'request_key' => (string) Str::uuid(),
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-17',
        'bill_number' => 'CM-777',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'line_total' => '2150', 'entry_mode' => 'total']],
        'costs' => [['cost_type' => 'freight', 'amount' => '50', 'payee' => 'supplier']],
        'pay_now' => true,
        'payment_money_account_id' => $this->w->bank->id,
        'payment_amount' => '1000',
    ];

    $response = $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', $form)->assertSessionHasNoErrors();
    $quick = QuickPurchase::query()->sole();
    $response->assertRedirect("/purchasing/buy-and-receive/{$quick->id}");

    // A double click: the same set, no second one.
    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', $form)->assertRedirect("/purchasing/buy-and-receive/{$quick->id}");
    expect(QuickPurchase::count())->toBe(1)->and(SupplierBill::count())->toBe(2)->and(SupplierPayment::count())->toBe(1);

    // The line total typed is the bill's total to the paisa.
    expect($quick->bill->total)->toContain('2150');

    $this->actingAs($this->w->owner)->get("/purchasing/buy-and-receive/{$quick->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('purchasing/buy-and-receive/show')
            ->where('purchase.status', 'completed')
            ->has('documents', 5)
            ->where('documents.0.number', $quick->purchaseOrder->po_number)
            ->where('documents.3.number', 'CM-777'));
});

it('puts a refusal from inside the chain on the screen and saves nothing', function () {
    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', [
        'request_key' => (string) Str::uuid(),
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-15',
        'bill_number' => 'CM-1',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '1', 'unit_cost' => '20']],
    ])->assertSessionHasErrors(['backdate_reason']);

    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', [
        'request_key' => (string) Str::uuid(),
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-17',
        'bill_number' => 'CM-1',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '1', 'unit_cost' => '20']],
        'pay_now' => true,
        'payment_money_account_id' => $this->w->bank->id,
        'payment_amount' => '50',
    ])->assertSessionHasErrors(['payment_amount']);

    expect(PurchaseOrder::count())->toBe(0)->and(QuickPurchase::count())->toBe(0);
});

it('keeps the screen behind its own permission, away from Sales and Finance', function () {
    $this->actingAs($this->w->sales)->get('/purchasing/buy-and-receive/new')->assertForbidden();
    $this->actingAs($this->w->sales)->post('/purchasing/buy-and-receive', [])->assertForbidden();

    // ⚠ Segregation of duties (decision 19 Oct 2026): Finance pays, it does
    // not also order and receive.
    $this->actingAs($this->w->financeA)->get('/purchasing/buy-and-receive/new')->assertForbidden();

    // A role with the screen but without the steps is told what it lacks.
    $role = Role::create(['name' => 'Looker', 'guard_name' => 'web', 'business_id' => $this->w->business->id]);
    $role->givePermissionTo(['purchasing.quick.purchase']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->w->member('Looker', 'Looker'))->get('/purchasing/buy-and-receive/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('missing', ['raise a purchase order', 'receive goods', 'record a supplier bill']));
});

it('grants the screen to Owner and Manager by migration, idempotently, never Finance', function () {
    $staff = Role::create(['name' => 'Staff', 'guard_name' => 'web', 'business_id' => $this->w->business->id]);
    $migration = require base_path('app/Modules/Purchasing/database/migrations/2026_10_19_300000_quick_purchases_buy_and_receive_on_one_screen.php');

    $migration->up();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['Owner', 'Manager'] as $name) {
        expect(Role::where('name', $name)->where('business_id', $this->w->business->id)->sole()->hasPermissionTo('purchasing.quick.purchase'))->toBeTrue();
    }

    expect($staff->fresh()->hasPermissionTo('purchasing.quick.purchase'))->toBeFalse()
        ->and(Role::where('name', 'Finance')->where('business_id', $this->w->business->id)->sole()->hasPermissionTo('purchasing.quick.purchase'))->toBeFalse()
        ->and(Role::where('name', 'Sales')->where('business_id', $this->w->business->id)->sole()->hasPermissionTo('purchasing.quick.purchase'))->toBeFalse();
});

// ── Purchasing approvals, for real (ruling P7, flag ON) ─────────────────────

describe('with purchasing approvals switched on', function () {
    beforeEach(function () {
        config(['accounting.purchasing_approvals' => true]);
    });

    it('buys below Tk 2 lakh with no approval at all', function () {
        $quick = ($this->buy)(['payment' => ['money_account' => $this->w->cash, 'amount' => '1000']]);

        expect($quick->status)->toBe(QuickPurchase::COMPLETED)
            ->and($quick->purchase_approval_id)->toBeNull()
            ->and(PurchaseApproval::count())->toBe(0)
            ->and(SupplierBill::count())->toBe(1)
            ->and(SupplierPayment::count())->toBe(1);
    });

    it('holds the bill as a PA- row when goods plus extra costs reach Tk 2 lakh, and posts it on approval', function () {
        // Goods Tk 199,000 alone are under the limit; with Tk 2,000 of freight
        // the purchase is Tk 201,000, so the bill waits for a second person.
        $quick = ($this->buy)([
            'lines' => [['product' => $this->d->salt, 'quantity' => '9950', 'unit_cost' => '20.00']],
            'extraCosts' => [['cost_type' => 'freight', 'amount' => '2000', 'payee' => 'supplier']],
            'payment' => ['money_account' => $this->w->bank, 'amount' => '50000'],
        ]);

        $approval = PurchaseApproval::query()->sole();

        expect($quick->status)->toBe(QuickPurchase::AWAITING_BILL_APPROVAL)
            ->and($quick->purchase_approval_id)->toBe($approval->id)
            ->and($quick->held_reason)->toContain($approval->number())
            ->and($approval->kind)->toBe('bill')
            ->and($approval->isPending())->toBeTrue()
            ->and($approval->amount_minor)->toBe(20100000)
            ->and(collect($approval->reasons)->pluck('code')->all())->toContain('threshold')
            // The goods came in and stay; nothing was billed or paid.
            ->and($quick->goods_receipt_id)->not->toBeNull()
            ->and(StockBatch::count())->toBe(1)
            ->and(SupplierBill::query()->where('bill_number', 'CM-501')->exists())->toBeFalse()
            ->and(SupplierPayment::count())->toBe(0);

        // The result page links the PA- row.
        $this->actingAs($this->w->owner)->get("/purchasing/buy-and-receive/{$quick->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('purchase.status', QuickPurchase::AWAITING_BILL_APPROVAL)
                ->where('documents', fn ($documents) => collect($documents)->contains(fn ($doc) => $doc['kind'] === 'approval'
                    && $doc['number'] === $approval->number()
                    && $doc['url'] === route('purchasing.approvals.show', $approval))));

        // The maker may not approve their own purchase; a Manager may.
        expect(fn () => app(PurchasingApprovalGate::class)->approve($approval, $this->w->owner))->toThrow(ValidationException::class);

        $bill = app(PurchasingApprovalGate::class)->approve($approval->fresh(), $this->w->manager);

        expect($bill)->toBeInstanceOf(SupplierBill::class)
            ->and($bill->bill_number)->toBe('CM-501')
            ->and($bill->purchase_order_id)->toBe($quick->purchase_order_id)
            ->and(Money::ofTaka((string) $bill->total)->minor)->toBe(19900000)
            ->and($approval->fresh()->status)->toBe(PurchaseApproval::APPROVED)
            ->and(($this->balance)('goods_received_not_invoiced'))->toBe(0);

        ($this->booksTie)();
    });

    it('records the bill and holds only the payment when the payment needs a second person', function () {
        // The first payment to a bank after the supplier's bank details changed.
        $this->supplier->forceFill(['bank_details_changed_at' => now()->subDay()])->save();

        $quick = ($this->buy)(['payment' => ['money_account' => $this->w->bank, 'amount' => '1000']]);
        $approval = PurchaseApproval::query()->sole();

        expect($quick->status)->toBe(QuickPurchase::AWAITING_PAYMENT_APPROVAL)
            ->and($quick->purchase_approval_id)->toBe($approval->id)
            ->and($approval->kind)->toBe('payment')
            ->and(collect($approval->reasons)->pluck('code')->all())->toBe(['bank_change'])
            ->and($quick->supplier_bill_id)->not->toBeNull()
            ->and(SupplierPayment::count())->toBe(0);

        $payment = app(PurchasingApprovalGate::class)->approve($approval, $this->w->manager);

        expect($payment)->toBeInstanceOf(SupplierPayment::class)
            ->and($quick->bill->fresh()->outstanding()->minor)->toBe(900000);
    });

    it('refuses a bill number that already waits for approval, before making anything', function () {
        ($this->buy)(['lines' => [['product' => $this->d->salt, 'quantity' => '10000', 'unit_cost' => '20.00']]]);
        expect(PurchaseApproval::count())->toBe(1);

        expect(fn () => ($this->buy)())
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['bill_number'][0])->toContain('waiting for approval'));

        expect(PurchaseOrder::count())->toBe(1);
    });
});

// ── Payment terms and duplicates on this screen (rulings P6, P5) ─────────────

it('keeps the supplier\'s terms on the bill it makes, and asks why a different due date was typed', function () {
    $this->supplier->forceFill(['payment_terms_type' => 'net_days', 'payment_terms_days' => 30])->save();

    $quick = ($this->buy)();

    expect($quick->bill->due_date->toDateString())->toBe('2026-11-16')
        ->and($quick->bill->due_date_source)->toBe('terms')
        ->and($quick->bill->payment_terms_days)->toBe(30);

    expect(fn () => ($this->buy)(['billNumber' => 'CM-502', 'dueDate' => '2026-10-20']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('due_date_reason'));
    expect(PurchaseOrder::count())->toBe(1);

    // A different amount, so it is not taken for the first bill typed twice.
    $other = ($this->buy)([
        'billNumber' => 'CM-503', 'dueDate' => '2026-10-20', 'dueDateReason' => 'Supplier asked for a week',
        'lines' => [['product' => $this->d->salt, 'quantity' => '40', 'unit_cost' => '20.00']],
    ]);

    expect($other->bill->due_date->toDateString())->toBe('2026-10-20')
        ->and($other->bill->due_date_source)->toBe('override')
        ->and($other->bill->due_date_reason)->toBe('Supplier asked for a week');
});

it('asks about a bill that looks like an earlier one, through the screen, and saves nothing until answered', function () {
    $form = fn (string $number, ?string $reason = null) => [
        'request_key' => (string) Str::uuid(),
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'purchased_on' => '2026-10-17',
        'bill_number' => $number,
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20']],
        'duplicate_reason' => $reason,
    ];

    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', $form('CM-901'))->assertSessionHasNoErrors();

    // Same supplier, same amount, same day, a number that differs only in punctuation.
    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', $form('CM901'))->assertSessionHasErrors(['duplicate']);
    expect(QuickPurchase::count())->toBe(1)->and(PurchaseOrder::count())->toBe(1);

    $this->actingAs($this->w->owner)->post('/purchasing/buy-and-receive', $form('CM901', 'A second delivery the same day'))->assertSessionHasNoErrors();
    expect(SupplierBill::query()->where('bill_number', 'CM901')->sole()->duplicate_override_reason)->toBe('A second delivery the same day');
});
