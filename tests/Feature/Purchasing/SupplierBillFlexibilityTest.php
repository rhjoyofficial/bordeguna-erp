<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierRefund;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Actions\ReverseSupplierRefund;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Support\LikelyDuplicateBill;
use App\Modules\Purchasing\Support\PurchaseOrderBilling;
use App\Modules\Purchasing\Support\ReceiptBilling;
use App\Modules\Purchasing\Support\SupplierBillDuplicates;
use App\Modules\Purchasing\Support\SupplierCreditBalance;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supplier bill flexibility (ruling P5, 19 Oct 2026): one bill across several
 * deliveries and orders of ONE supplier, matched per delivery line; service
 * lines with no order (Dr expense / Cr AP, flagged non-PO); carriage through
 * the landed-cost engine, never also on the bill; likely-duplicate warnings
 * with a reason; and the supplier paying back what they owe us on AP.
 * Ruling minimum tests 24 to 28, plus the refund rules from "Payments and
 * refunds".
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-19 10:00:00');

    $this->business = Business::create(['name' => 'Flex Co', 'slug' => 'flex-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Accountant'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    $this->seed(PurchasingPermissionsSeeder::class);
    (new ApprovalWorkflowSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->asManager = fn () => $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id]);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->oil = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'OIL', 'name' => 'Mustard oil', 'type' => ProductType::RawMaterial]);
    $this->salt = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'SALT', 'name' => 'Salt', 'type' => ProductType::RawMaterial]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Razzak Mill', 'code' => 'SUP-001']);
    $this->other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Other Traders', 'code' => 'SUP-002']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->order = function (Product $product, string $quantity, string $cost, ?Supplier $supplier = null): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $supplier ?? $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->manager,
        );
        approveIfPending($order->approvalRequest, $this->manager);

        return $order->fresh(['lines', 'approvalRequest']);
    };

    $this->receive = fn (PurchaseOrder $order, string $quantity, ?string $challan = null): GoodsReceipt => app(RecordGoodsReceipt::class)->handle(
        $order->fresh(['lines', 'approvalRequest']),
        [['line' => $order->lines->first()->fresh(), 'quantity' => $quantity]],
        $this->manager,
        supplierChallanNumber: $challan,
        receivedOn: Carbon::parse('2026-10-15'),
        backdateReason: 'test',
    )->fresh('lines');

    $this->goods = fn (GoodsReceipt $receipt, string $quantity, string $cost) => [
        'line' => $receipt->lines->first()->purchaseOrderLine->fresh(),
        'receiptLine' => $receipt->lines->first(),
        'quantity' => $quantity,
        'unitCost' => $cost,
    ];

    $this->record = fn (array $lines, string $number = 'RZK-501', array $extra = []) => app(RecordSupplierBill::class)->handle(...[
        'supplier' => $this->supplier,
        'billNumber' => $number,
        'billDate' => '2026-10-18',
        'lines' => $lines,
        'createdBy' => $this->manager,
        ...$extra,
    ]);

    $this->onAccount = fn (string $slug, ?Supplier $party = null) => (int) JournalLine::query()
        ->where('account_id', Account::bySlug($slug)->id)
        ->when($party, fn ($q) => $q->where('party_type', $party->getMorphClass())->where('party_id', $party->id))
        ->sum('amount_minor');

    $this->grni = fn () => AccountMapping::resolve('inventory.goods_received_not_invoiced', Carbon::today());
});

afterEach(fn () => Carbon::setTestNow());

// ── 24. One bill, several orders and deliveries, one supplier ───────────────

it('bills two deliveries from two orders of one supplier on one bill, clearing GRNI per line with the supplier tag', function () {
    $oilOrder = ($this->order)($this->oil, '100', '300.00');
    $saltOrder = ($this->order)($this->salt, '50', '40.00');
    $oilIn = ($this->receive)($oilOrder, '100', 'CH-11');
    $saltIn = ($this->receive)($saltOrder, '50', 'CH-12');

    $bill = ($this->record)([($this->goods)($oilIn, '100', '300.00'), ($this->goods)($saltIn, '50', '40.00')]);

    expect($bill->purchase_order_id)->toBeNull()
        ->and((float) $bill->total)->toBe(32000.0)
        ->and($bill->lines->pluck('goods_receipt_line_id')->all())->toBe([$oilIn->lines->first()->id, $saltIn->lines->first()->id]);

    // GRNI back to zero for this supplier, by two debit lines that each name
    // the supplier, the order and the challan.
    $grniLines = JournalLine::query()->where('account_id', ($this->grni)()->id)
        ->whereHas('journal', fn ($q) => $q->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id))->get();

    expect($grniLines)->toHaveCount(2)
        ->and($grniLines->every(fn ($l) => $l->party_id === $this->supplier->id))->toBeTrue()
        ->and($grniLines->pluck('memo')->implode(' '))->toContain('CH-11')->toContain('CH-12')
        ->and(($this->onAccount)('goods_received_not_invoiced', $this->supplier))->toBe(0)
        ->and(($this->onAccount)('accounts_payable', $this->supplier))->toBe(-3_200_000);

    // Each order sees its own share of the shared bill.
    expect(PurchaseOrderBilling::summarise($oilOrder->fresh(PurchaseOrderBilling::eagerLoad()))['billed'])->toBe('30000.00')
        ->and(PurchaseOrderBilling::summarise($saltOrder->fresh(PurchaseOrderBilling::eagerLoad()))['billed'])->toBe('2000.00');
});

it('refuses a line ordered from another supplier on the bill', function () {
    $mine = ($this->order)($this->oil, '10', '300.00');
    $theirs = ($this->order)($this->salt, '10', '40.00', $this->other);
    $a = ($this->receive)($mine, '10');
    $b = ($this->receive)($theirs, '10');

    expect(fn () => ($this->record)([($this->goods)($a, '10', '300.00'), ($this->goods)($b, '10', '40.00')]))
        ->toThrow(InvalidArgumentException::class, 'different supplier');

    expect(SupplierBill::count())->toBe(0);
});

it('never bills a delivery line beyond what arrived on it, and counts older unlinked billing oldest first', function () {
    $order = ($this->order)($this->oil, '10', '300.00');
    $first = ($this->receive)($order, '6', 'CH-1');
    $second = ($this->receive)($order, '4', 'CH-2');

    // 7 on the 4 kg delivery: refused although the order still has 10 open.
    expect(fn () => ($this->record)([($this->goods)($second, '7', '300.00')]))
        ->toThrow(InvalidArgumentException::class, 'only 4.0000 of that delivery');

    // A bill that names no delivery (the old way) bills 3 of the order line:
    // taken from the oldest delivery first.
    ($this->record)([['line' => $order->lines->first()->fresh(), 'quantity' => '3', 'unitCost' => '300.00']], 'OLD-1', ['purchaseOrder' => $order]);

    $billable = ReceiptBilling::billableByReceiptLine($order->lines->first()->fresh());
    expect($billable[$first->lines->first()->id])->toBe('3.0000')
        ->and($billable[$second->lines->first()->id])->toBe('4.0000');

    expect(fn () => ($this->record)([($this->goods)($first, '4', '300.00')], 'RZK-2'))->toThrow(InvalidArgumentException::class, 'only 3.0000');

    ($this->record)([($this->goods)($first, '3', '300.00'), ($this->goods)($second, '4', '300.00')], 'RZK-3');

    expect((string) $order->lines->first()->fresh()->quantity_invoiced)->toBe('10.0000')
        ->and(ReceiptBilling::openReceiptsOf($this->supplier))->toHaveCount(0);
});

// ── 28. A bill before the goods: no phantom stock ──────────────────────────

it('refuses to bill goods not yet received and writes no stock for a bill', function () {
    $order = ($this->order)($this->oil, '10', '300.00');
    $movements = StockMovement::count();

    expect(fn () => ($this->record)([['line' => $order->lines->first(), 'quantity' => '10', 'unitCost' => '300.00']], 'EARLY-1', ['purchaseOrder' => $order]))
        ->toThrow(InvalidArgumentException::class);

    ($this->record)([], 'SVC-1', ['expenseLines' => [['account' => Account::where('code', '6052')->firstOrFail(), 'amount' => '1500', 'description' => 'October electricity']]]);

    expect(StockMovement::count())->toBe($movements);
});

// ── 25. Service lines with no purchase order ───────────────────────────────

it('posts a service line Dr the expense head / Cr AP with the supplier, and flags the bill as non-PO', function () {
    $electricity = Account::where('code', '6052')->firstOrFail();
    $order = ($this->order)($this->oil, '10', '300.00');
    $in = ($this->receive)($order, '10');

    $bill = ($this->record)([($this->goods)($in, '10', '300.00')], 'MIX-1', [
        'expenseLines' => [['account' => $electricity, 'amount' => '1250.50', 'description' => 'Mill power share']],
    ]);

    expect($bill->has_non_po_lines)->toBeTrue()
        ->and((float) $bill->total)->toBe(4250.5);

    $journal = Journal::query()->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->with('lines')->firstOrFail();
    $expense = $journal->lines->firstWhere('account_id', $electricity->id);

    expect($expense->amount_minor)->toBe(125_050)
        ->and($journal->lines->where('account_id', Account::bySlug('purchase_price_variance')->id))->toHaveCount(0)
        ->and(($this->onAccount)('accounts_payable', $this->supplier))->toBe(-425_050)
        ->and(($this->onAccount)('goods_received_not_invoiced', $this->supplier))->toBe(0);

    // A goods-only bill is not flagged.
    $plain = ($this->order)($this->salt, '5', '40.00');
    expect(($this->record)([($this->goods)(($this->receive)($plain, '5'), '5', '40.00')], 'PLAIN-1')->has_non_po_lines)->toBeFalse();
});

it('refuses a service line on a head that is not an expense or is owned by another module', function () {
    $sales = Account::query()->where('type', 'income')->where('is_postable', true)->firstOrFail();

    expect(fn () => ($this->record)([], 'BAD-1', ['expenseLines' => [['account' => $sales, 'amount' => '100', 'description' => 'x']]]))
        ->toThrow(InvalidArgumentException::class, 'pick an active expense head');

    expect(fn () => ($this->record)([], 'BAD-2', ['expenseLines' => [['account' => Account::bySlug('purchase_price_variance'), 'amount' => '100', 'description' => 'x']]]))
        ->toThrow(InvalidArgumentException::class);

    expect(SupplierBill::count())->toBe(0);
});

// ── 26. Carriage through landed cost, never twice ──────────────────────────

it('sends carriage on the bill through landed cost: into the stock value, owed once, not in the bill total', function () {
    $order = ($this->order)($this->oil, '100', '300.00');
    $in = ($this->receive)($order, '100', 'CH-7');
    $stockBefore = ($this->onAccount)('stock_on_hand');

    $bill = ($this->record)([($this->goods)($in, '100', '300.00')], 'RZK-CAR', [
        'carriageLines' => [['goods_receipt_id' => $in->id, 'cost_type' => 'freight', 'amount' => '2000', 'allocation_method' => 'value']],
    ]);

    $landed = LandedCost::query()->where('supplier_bill_id', $bill->id)->firstOrFail();

    expect((float) $bill->total)->toBe(30000.0)
        ->and($landed->status)->toBe(LandedCostStatus::Posted)
        ->and($landed->total_paisa)->toBe(200_000)
        // Freight is carriage inward: into the stock (IAS 2), not an expense.
        ->and(($this->onAccount)('stock_on_hand') - $stockBefore)->toBe(200_000)
        // Owed ONCE: the goods on the bill, the carriage through the landed cost.
        ->and(($this->onAccount)('accounts_payable', $this->supplier))->toBe(-3_200_000)
        // ...and the landed cost raised the bill a payment settles.
        ->and(SupplierBill::query()->where('landed_cost_id', $landed->id)->value('total'))->not->toBeNull();

    // The supplier's AP still reconciles document by document.
    expect(collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'] ?? [])->filter(fn ($p) => str_contains((string) $p, 'Accounts Payable')))->toBeEmpty();

    // The bill cannot be reversed while its carriage stands as a posted landed cost.
    expect(fn () => app(ReverseSupplierBill::class)->handle($bill, $this->manager))->toThrow(DomainException::class, $landed->number);
});

it('leaves carriage as a draft for someone without landed-cost rights, and a reversal of the bill removes that draft', function () {
    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);
    $clerk->givePermissionTo('purchasing.bill.record');

    $order = ($this->order)($this->oil, '10', '300.00');
    $in = ($this->receive)($order, '10');

    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: 'RZK-DRAFT', billDate: '2026-10-18',
        lines: [($this->goods)($in, '10', '300.00')], createdBy: $clerk,
        carriageLines: [['goods_receipt_id' => $in->id, 'cost_type' => 'freight', 'amount' => '500']],
    );

    $landed = LandedCost::query()->where('supplier_bill_id', $bill->id)->firstOrFail();
    expect($landed->status)->toBe(LandedCostStatus::Draft)
        ->and($landed->isEditable())->toBeTrue();

    app(ReverseSupplierBill::class)->handle($bill, $this->manager, 'typed twice');

    expect(LandedCost::query()->whereKey($landed->id)->exists())->toBeFalse();
});

// ── 27. Duplicate supplier invoices ────────────────────────────────────────

it('normalises invoice numbers: case, spaces, punctuation and leading zeros do not make a new bill', function () {
    expect(SupplierBillDuplicates::normalise(' inv-0012 '))->toBe('INV12')
        ->and(SupplierBillDuplicates::normalise('INV/12'))->toBe('INV12')
        ->and(SupplierBillDuplicates::normalise('2026/007'))->toBe('20267');
});

it('warns on a likely duplicate when asked, and records it with a reason', function () {
    $order = ($this->order)($this->oil, '20', '300.00');
    $in = ($this->receive)($order, '20');

    ($this->record)([($this->goods)($in, '10', '300.00')], 'INV-0012');

    // The exact number is refused outright, as it always was.
    expect(fn () => ($this->record)([($this->goods)($in, '5', '300.00')], 'INV-0012'))->toThrow(InvalidArgumentException::class);

    // Written differently: a warning, nothing saved.
    try {
        ($this->record)([($this->goods)($in, '5', '300.00')], 'inv 12', ['warnOnLikelyDuplicates' => true]);
        $this->fail('Expected a likely-duplicate warning.');
    } catch (LikelyDuplicateBill $e) {
        expect($e->matches)->toHaveCount(1)->and($e->getMessage())->toContain('INV-0012');
    }

    // Same amount within seven days under another number: a warning too.
    expect(fn () => ($this->record)([($this->goods)($in, '10', '300.00')], 'RZK-900', ['warnOnLikelyDuplicates' => true]))
        ->toThrow(LikelyDuplicateBill::class, 'the same amount');

    expect(SupplierBill::count())->toBe(1);

    // With a reason it is recorded, and the reason and the look-alike are kept.
    $bill = ($this->record)([($this->goods)($in, '10', '300.00')], 'RZK-900', ['warnOnLikelyDuplicates' => true, 'duplicateReason' => 'Second delivery, same size']);

    expect($bill->fresh()->duplicate_override_reason)->toBe('Second delivery, same size')
        ->and($bill->fresh()->duplicate_matches[0]['bill_number'])->toBe('INV-0012');
});

// ── Payments and refunds: the supplier pays back what they owe us ──────────

it('records a supplier refund of a debit balance on AP, capped at it, and reverses it', function () {
    $payable = Account::bySlug('accounts_payable');

    // A journal voucher left the supplier owing us 1,000 on AP.
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-10-10'),
        lines: [
            JournalLineDraft::debit($payable->id, Money::ofTaka('1000'), 'Overpaid by voucher', $this->supplier),
            JournalLineDraft::credit(Account::bySlug('cash_at_bank')->id, Money::ofTaka('1000'), 'BRAC', null, $this->bank),
        ],
        memo: 'Overpayment',
    ), $this->manager);

    expect(SupplierCreditBalance::of($this->supplier)->toTaka())->toBe('1000.00');

    $refund = app(RecordSupplierRefund::class)->handle($this->supplier, $this->bank, '2026-10-18', '600', $this->manager, 'BRAC in 77');

    expect($refund->number)->toBe('SREF-2026-27-0001')
        ->and(($this->onAccount)('accounts_payable', $this->supplier))->toBe(40_000)
        ->and(SupplierCreditBalance::of($this->supplier)->toTaka())->toBe('400.00');

    expect(fn () => app(RecordSupplierRefund::class)->handle($this->supplier, $this->bank, '2026-10-18', '400.01', $this->manager))
        ->toThrow(ValidationException::class);

    app(ReverseSupplierRefund::class)->handle($refund, $this->manager, 'Bank returned it');

    expect($refund->fresh()->isReversed())->toBeTrue()
        ->and(SupplierCreditBalance::of($this->supplier)->toTaka())->toBe('1000.00');
});

it('refuses a refund when the supplier only holds an advance, which is refunded as an advance', function () {
    app(RecordSupplierAdvance::class)->pay(supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-10-10', amount: '5000', createdBy: $this->manager);

    expect(fn () => app(RecordSupplierRefund::class)->handle($this->supplier, $this->bank, '2026-10-18', '100', $this->manager))
        ->toThrow(ValidationException::class);

    expect(SupplierRefund::count())->toBe(0);
});

// ── Screens: route, popup data, permissions ────────────────────────────────

it('opens a bill for a supplier with their open deliveries, expense heads and cost types', function () {
    $order = ($this->order)($this->oil, '10', '300.00');
    ($this->receive)($order, '10', 'CH-90');

    ($this->asManager)()->get('/purchasing/bills/new?supplier='.$this->supplier->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('purchasing/bills/create')
            ->where('order.supplier.id', $this->supplier->id)
            ->has('receipts', 1)
            ->where('receipts.0.challan', 'CH-90')
            ->has('expense_accounts')
            ->has('cost_types'));

    ($this->asManager)()->get('/purchasing/bills')
        ->assertInertia(fn (Assert $page) => $page->has('bill_suppliers', 2));
});

it('records a bill across two orders with a service line from the screen, and asks before a likely duplicate', function () {
    $a = ($this->order)($this->oil, '10', '300.00');
    $b = ($this->order)($this->salt, '10', '40.00');
    $inA = ($this->receive)($a, '10');
    $inB = ($this->receive)($b, '10');
    $line = fn (GoodsReceipt $in, string $cost) => [
        'purchase_order_line_id' => $in->lines->first()->purchase_order_line_id,
        'goods_receipt_line_id' => $in->lines->first()->id,
        'quantity' => '10', 'unit_cost' => $cost,
    ];

    ($this->asManager)()->post('/purchasing/bills', [
        'supplier_id' => $this->supplier->id,
        'bill_number' => 'RZK-77',
        'bill_date' => '2026-10-18',
        'lines' => [$line($inA, '300.00'), $line($inB, '40.00')],
        'services' => [['expense_account_id' => Account::where('code', '6052')->value('id'), 'description' => 'Power', 'amount' => '100']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $bill = SupplierBill::query()->where('bill_number', 'RZK-77')->firstOrFail();
    expect((float) $bill->total)->toBe(3500.0)
        ->and($bill->has_non_po_lines)->toBeTrue()
        ->and($bill->purchase_order_id)->toBeNull();

    // The same 3,500 again under another number: the screen asks why.
    ($this->asManager)()->post('/purchasing/bills', [
        'supplier_id' => $this->supplier->id,
        'bill_number' => 'RZK-78',
        'bill_date' => '2026-10-18',
        'services' => [['expense_account_id' => Account::where('code', '6052')->value('id'), 'description' => 'Power', 'amount' => '3500']],
    ])->assertSessionHasErrors('duplicate');

    ($this->asManager)()->get("/purchasing/bills/{$bill->id}")
        ->assertInertia(fn (Assert $page) => $page->where('bill.has_non_po_lines', true)->has('bill.orders', 2));
});

it('lets Finance-style roles refund on the supplier page and keeps the Accountant out', function () {
    $accountant = User::factory()->create();
    $accountant->businesses()->attach($this->business->id);
    $accountant->assignRole('Accountant');

    ($this->asManager)()->get("/purchasing/suppliers/{$this->supplier->id}/advances")
        ->assertInertia(fn (Assert $page) => $page->where('credit.balance', '0.00')->has('credit.refunds', 0));

    $this->actingAs($accountant)->withSession(['current_business_id' => $this->business->id])
        ->post('/purchasing/supplier-refunds', [
            'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id,
            'received_on' => '2026-10-18', 'amount' => '10',
        ])->assertForbidden();

    ($this->asManager)()->post('/purchasing/supplier-refunds', [
        'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id,
        'received_on' => '2026-10-18', 'amount' => '10',
    ])->assertSessionHasErrors('amount');
});
