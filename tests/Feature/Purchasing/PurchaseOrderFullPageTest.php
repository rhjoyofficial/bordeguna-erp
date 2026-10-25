<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderCharge;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DiscountWorld;

/**
 * ⭐ The full New purchase order page (purchase redesign PR1, 18 Oct 2026).
 * Joy: "no discount, no multiple payments, no shipping/freight, no VAT;
 * auto-generate what can be; too many steps; product cost must come from all
 * its related costs."
 *
 * Every field saves; the figures are the server's; the charges become the
 * receipt's landed cost once, however the goods arrive; the bill opens with
 * the order's discount and VAT; "pay now" is an advance (SADV) or, with the
 * switches on, the payment of the one-page purchase; approvals still hold.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;
    $this->supplier = $this->d->supplier;

    $this->as = fn (?User $user = null) => $this->actingAs($user ?? $this->w->owner)->withSession(['current_business_id' => $this->w->business->id]);

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->booksTie = fn () => expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty()
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBeEmpty();

    // Salt 100 kg at 20 less 10%, VAT 5%; sugar 50 kg at 40 less Tk 100;
    // 5% off the order; freight Tk 500 owed to this supplier, unloading
    // Tk 250 paid from cash; rounded down by 50 paisa; two part payments.
    $this->payload = fn (array $overrides = []) => $overrides + [
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'order_date' => '2026-10-17',
        'expected_date' => '2026-10-20',
        'supplier_reference' => 'QT-77',
        'buyer_id' => $this->w->manager->id,
        'terms_text' => 'Packed in 50 kg sacks.',
        'note' => 'Monthly salt and sugar',
        'discount_type' => 'percent',
        'discount_value' => '5',
        'rounding' => '-0.50',
        'lines' => [
            ['product_id' => $this->d->salt->id, 'description' => 'Fine grade', 'quantity' => '100', 'unit_cost' => '20', 'entry_mode' => 'rate', 'discount_type' => 'percent', 'discount_value' => '10', 'tax_rate' => '5'],
            ['product_id' => $this->d->sugar->id, 'quantity' => '50', 'unit_cost' => '40', 'entry_mode' => 'rate', 'discount_type' => 'amount', 'discount_value' => '100', 'tax_rate' => ''],
        ],
        'charges' => [
            ['cost_type' => 'freight', 'amount' => '500', 'allocation_method' => 'value', 'payee' => 'this'],
            ['cost_type' => 'labour', 'amount' => '250', 'allocation_method' => 'value', 'payee' => 'money', 'money_account_id' => $this->w->cash->id],
        ],
        'schedule' => [
            ['due_on' => '2026-10-20', 'amount' => '2000', 'note' => 'Half before delivery'],
            ['due_on' => '2026-11-01', 'amount' => '2100', 'note' => 'The rest'],
        ],
    ];

    $this->store = fn (array $overrides = [], ?User $user = null) => ($this->as)($user)->post(route('purchasing.purchase-orders.store'), ($this->payload)($overrides));

    $this->receiveAll = function (PurchaseOrder $order, array $quantities = []): void {
        $order = $order->fresh(['lines', 'approvalRequest']);
        app(RecordGoodsReceipt::class)->handle(
            $order,
            $order->lines->values()->map(fn ($line, $i) => ['line' => $line, 'quantity' => $quantities[$i] ?? (string) $line->quantity_ordered])->all(),
            $this->w->owner,
            receivedOn: '2026-10-17',
        );
    };
});

afterEach(fn () => Carbon::setTestNow());

it('opens a full page with every default it can fill, for whoever may raise orders', function () {
    // The supplier's usual warehouse and lead time.
    $this->supplier->forceFill(['lead_time_days' => 3])->save();
    $order = app(CreatePurchaseOrder::class)->handle($this->supplier, $this->d->warehouse, [['product' => $this->d->salt, 'quantity' => '10', 'unit_cost' => '18.50']], $this->w->owner, orderDate: '2026-10-10');
    ($this->receiveAll)($order);

    ($this->as)()->get(route('purchasing.purchase-orders.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/form')
            ->where('order', null)
            ->where('nextNumber', 'PO-000002')
            ->where('today', '2026-10-17')
            ->where('currentUserId', $this->w->owner->id)
            ->where('suppliers.0.lead_time_days', 3)
            ->where('suppliers.0.last_warehouse_id', $this->d->warehouse->id)
            ->where('products.1.name', 'Salt, bulk')
            ->where('products.1.unit', 'kg')
            ->where('products.1.last_cost.unit_cost', '18.50')
            ->where('vat.applies', false)
            ->where('rights.bill', true)
            ->has('costTypes', 12));

    // A person who may only read purchases is refused the page.
    ($this->as)($this->w->sales)->get(route('purchasing.purchase-orders.create'))->assertForbidden();
});

it('saves every field of the page and the figures the server works out', function () {
    Storage::fake('local');

    ($this->store)(['attachments' => [UploadedFile::fake()->create('quotation.pdf', 20, 'application/pdf')]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order = PurchaseOrder::query()->with(['lines', 'charges', 'paymentSchedule', 'attachments'])->sole();
    [$salt, $sugar] = $order->lines->sortBy('id')->values()->all();

    // VAT is off for this business, so the 5% VAT is part of the salt's cost.
    expect($order->supplier_reference)->toBe('QT-77')
        ->and($order->buyer_id)->toBe($this->w->manager->id)
        ->and($order->terms_text)->toBe('Packed in 50 kg sacks.')
        ->and($order->vat_in_cost)->toBeTrue()
        ->and($order->goods_paisa)->toBe(400000)
        ->and($order->discount_paisa)->toBe(48500)
        ->and(Money::ofTaka((string) $order->tax_total)->minor)->toBe(8550)
        ->and($order->charges_paisa)->toBe(75000)
        ->and($order->supplier_charges_paisa)->toBe(50000)
        ->and($order->rounding_paisa)->toBe(-50)
        ->and($order->grand_total_paisa)->toBe(410000)
        ->and($order->attachments)->toHaveCount(1);

    expect($salt->description)->toBe('Fine grade')
        ->and($salt->gross_paisa)->toBe(200000)
        ->and($salt->discount_paisa)->toBe(20000)
        ->and($salt->order_discount_paisa)->toBe(9000)
        ->and((string) $salt->tax_amount)->toBe('85.5000')
        // The value GRNI and the bill read: the goods plus the VAT nobody can reclaim.
        ->and($salt->line_total_paisa)->toBe(208550)
        ->and($salt->entry_mode)->toBe(PurchaseEntryMode::Total)
        ->and($sugar->discount_paisa)->toBe(10000)
        ->and($sugar->order_discount_paisa)->toBe(9500)
        ->and($sugar->line_total_paisa)->toBe(200000)
        ->and($sugar->entry_mode)->toBe(PurchaseEntryMode::Rate);

    expect($order->charges->pluck('amount_paisa')->all())->toBe([50000, 25000])
        ->and($order->charges[0]->supplier_id)->toBe($this->supplier->id)
        ->and($order->charges[1]->money_account_id)->toBe($this->w->cash->id)
        ->and($order->paymentSchedule->pluck('amount_paisa')->all())->toBe([200000, 210000]);

    // The order page and the print show all of it.
    ($this->as)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('full.supplier_reference', 'QT-77')
            ->where('full.grand_total', '4100.00')
            ->where('full.discount', '485.00')
            ->where('full.lines.0.discount', '290.00')
            ->where('full.charges.0.remaining', '500.00')
            ->has('full.schedule', 2)
            ->has('full.attachments', 1)
            ->where('can_edit', true));

    ($this->as)()->get(route('purchasing.purchase-orders.print', $order))
        ->assertInertia(fn (Assert $page) => $page->where('total', '4100.00')->where('full.terms_text', 'Packed in 50 kg sacks.'));
});

it('turns the charges into the receipt\'s landed cost and the discounts into the bill\'s, so the item cost is everything it cost', function () {
    ($this->store)()->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();

    ($this->receiveAll)($order);

    // The two charges became one posted landed cost on that receipt.
    $landed = LandedCost::query()->with('lines')->sole();
    expect($landed->status)->toBe(LandedCostStatus::Posted)
        ->and($landed->total_paisa)->toBe(75000)
        ->and(PurchaseOrderCharge::query()->pluck('landed_paisa')->all())->toBe([50000, 25000])
        // Goods at their order value (VAT inside), plus every charge.
        ->and(($this->balance)('stock_on_hand'))->toBe(408550 + 75000);

    // The bill opens with the order's discount, line by line.
    ($this->as)()->get(route('purchasing.bills.create', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipts.0.lines.0.order_discount_for_billable', '290.00')
            ->where('receipts.0.lines.1.order_discount_for_billable', '195.00')
            ->where('receipts.0.lines.0.order_reference', 'QT-77'));

    $receiptLines = $order->goodsReceipts()->sole()->lines()->orderBy('id')->get();
    $orderLines = $order->lines()->orderBy('id')->get();

    ($this->as)()->post('/purchasing/bills', [
        'purchase_order_id' => $order->id,
        'bill_number' => 'INV-9',
        'bill_date' => '2026-10-17',
        'discount_mode' => 'lines',
        'discount_reference' => 'QT-77',
        'lines' => [
            ['purchase_order_line_id' => $orderLines[0]->id, 'goods_receipt_line_id' => $receiptLines[0]->id, 'quantity' => '100', 'line_total' => '2085.50', 'entry_mode' => 'total', 'discount' => '290.00', 'tax_rate' => '5'],
            ['purchase_order_line_id' => $orderLines[1]->id, 'goods_receipt_line_id' => $receiptLines[1]->id, 'quantity' => '50', 'unit_cost' => '40', 'entry_mode' => 'rate', 'discount' => '195.00'],
        ],
    ])->assertSessionHasNoErrors();

    $bill = SupplierBill::query()->whereNull('landed_cost_id')->sole();

    // goods 4,000 - discounts 485 + VAT 85.50 + charges 750: what it all cost.
    expect((float) SupplierDiscount::query()->sum('amount'))->toBe(485.0)
        ->and($bill->outstanding()->minor)->toBe(408550 - 48500)
        ->and(($this->balance)('stock_on_hand'))->toBe(400000 - 48500 + 8550 + 75000)
        ->and(($this->balance)('goods_received_not_invoiced'))->toBe(0);

    ($this->booksTie)();
});

it('gives each delivery its share of the charges and never counts one twice', function () {
    ($this->store)([
        'discount_type' => '', 'discount_value' => '', 'rounding' => '', 'schedule' => [],
        'lines' => [
            ['product_id' => $this->d->salt->id, 'quantity' => '100', 'unit_cost' => '20'],
            ['product_id' => $this->d->sugar->id, 'quantity' => '50', 'unit_cost' => '40'],
        ],
        'charges' => [['cost_type' => 'freight', 'amount' => '600', 'allocation_method' => 'value', 'payee' => 'this']],
    ])->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();

    // Half the salt: 1,000 of the order's 4,000, so a quarter of the freight.
    ($this->receiveAll)($order, ['50', '0']);
    expect(PurchaseOrderCharge::query()->sole()->landed_paisa)->toBe(15000);

    // The rest arrives: it takes exactly what is left.
    ($this->receiveAll)($order->fresh(), ['50', '50']);
    expect(PurchaseOrderCharge::query()->sole()->landed_paisa)->toBe(60000)
        ->and(LandedCost::query()->orderBy('id')->pluck('total_paisa')->all())->toBe([15000, 45000]);

    ($this->booksTie)();
});

it('does the whole purchase on the page when the switches are on: order, receipt, landed cost, bill and payment', function () {
    ($this->store)([
        'receive_now' => true,
        'bill_now' => true,
        'pay_now' => true,
        'request_key' => (string) Str::uuid(),
        'bill_number' => 'CM-77',
        'challan_number' => 'CH-1',
        'payment_money_account_id' => $this->w->bank->id,
        'payment_amount' => '3000',
        'schedule' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $quick = QuickPurchase::query()->sole();
    $order = $quick->purchaseOrder;

    expect($quick->status)->toBe(QuickPurchase::COMPLETED)
        ->and($order->supplier_reference)->toBe('QT-77')
        ->and($quick->landed_cost_id)->not->toBeNull()
        ->and($quick->landedCost->status)->toBe(LandedCostStatus::Posted)
        ->and($quick->bill->bill_number)->toBe('CM-77')
        // The order's discounts are the bill's trade discount.
        ->and((float) SupplierDiscount::query()->where('supplier_bill_id', $quick->supplier_bill_id)->sum('amount'))->toBe(485.0)
        ->and($quick->paid_paisa)->toBe(300000)
        ->and(($this->balance)('stock_on_hand'))->toBe(400000 - 48500 + 8550 + 75000);

    ($this->booksTie)();
});

it('receives the goods on the page without a bill when only that switch is on', function () {
    ($this->store)(['receive_now' => true, 'challan_number' => 'CH-2'])->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();

    expect($order->goodsReceipts()->sole()->supplier_challan_number)->toBe('CH-2')
        ->and(SupplierBill::query()->whereNull('landed_cost_id')->count())->toBe(0)
        ->and(LandedCost::query()->sole()->status)->toBe(LandedCostStatus::Posted);
});

it('numbers the batches itself when "Receive now" leaves them blank, and the receipt page shows the landed cost', function () {
    // Integration PR1 + PR2 (18 Oct 2026): a product that tracks expiry with
    // a known shelf life needs nothing typed on the page.
    $this->d->salt->forceFill(['tracks_expiry' => true, 'shelf_life_days' => 180])->save();

    ($this->store)(['receive_now' => true, 'challan_number' => 'CH-3'])->assertSessionHasNoErrors();

    $receipt = PurchaseOrder::query()->sole()->goodsReceipts()->sole();
    $salt = StockBatch::query()->where('product_id', $this->d->salt->id)->sole();

    expect($salt->batch_number)->toMatch('/-261017-01$/')
        ->and($salt->manufactured_date?->toDateString())->toBe('2026-10-17')
        ->and($salt->expiry_date?->toDateString())->toBe('2027-04-15');

    ($this->as)()->get(route('purchasing.receipts.show', $receipt))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/receipts/show')
            ->has('landed_costs', 1)
            ->where('landed_costs.0.total', '750.00'));
});

it('pays an advance against the order when "pay now" has no bill, and holds a large one for approval', function () {
    ($this->store)(['pay_now' => true, 'payment_money_account_id' => $this->w->bank->id, 'payment_amount' => '1000', 'payment_reference' => 'CHQ-5'])
        ->assertSessionHasNoErrors();

    $order = PurchaseOrder::query()->sole();
    $advance = SupplierAdvance::query()->sole();

    expect($advance->purchase_order_id)->toBe($order->id)
        ->and($advance->money()->minor)->toBe(100000)
        ->and($advance->reference)->toBe('CHQ-5');

    ($this->as)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('full.advance_paid', '1000.00')->where('full.balance_due', '3100.00'));

    // Over the Tk 200,000 limit the advance waits for a second person; the order stays.
    config(['accounting.purchasing_approvals' => true]);

    ($this->store)([
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '20000', 'unit_cost' => '20']],
        'charges' => [], 'schedule' => [], 'discount_type' => '', 'discount_value' => '', 'rounding' => '',
        'pay_now' => true, 'payment_money_account_id' => $this->w->bank->id, 'payment_amount' => '250000',
    ])->assertSessionHasNoErrors()->assertSessionHas('warning');

    expect(PurchaseOrder::count())->toBe(2)
        ->and(SupplierAdvance::count())->toBe(1)
        ->and(PurchaseApproval::query()->where('kind', 'advance')->where('status', PurchaseApproval::PENDING)->count())->toBe(1);
});

it('holds the one-page purchase for approval over the limit, with the goods received', function () {
    config(['accounting.purchasing_approvals' => true]);

    ($this->store)([
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '20000', 'unit_cost' => '20']],
        'charges' => [], 'schedule' => [], 'discount_type' => '', 'discount_value' => '', 'rounding' => '',
        'receive_now' => true, 'bill_now' => true, 'bill_number' => 'BIG-1', 'request_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $quick = QuickPurchase::query()->sole();

    expect($quick->status)->toBe(QuickPurchase::AWAITING_BILL_APPROVAL)
        ->and($quick->goods_receipt_id)->not->toBeNull()
        ->and($quick->supplier_bill_id)->toBeNull()
        ->and(PurchaseApproval::query()->where('kind', 'bill')->count())->toBe(1);
});

it('refuses figures that cannot be right, each under its own box', function () {
    ($this->store)([
        'lines' => [
            ['product_id' => $this->d->salt->id, 'quantity' => '10', 'unit_cost' => '20', 'discount_type' => 'percent', 'discount_value' => '150'],
            ['product_id' => $this->d->sugar->id, 'quantity' => '10', 'unit_cost' => '20', 'discount_type' => 'amount', 'discount_value' => '500'],
        ],
        'charges' => [
            ['cost_type' => 'freight', 'amount' => '50', 'allocation_method' => 'direct', 'payee' => 'this'],
            ['cost_type' => 'labour', 'amount' => '50', 'payee' => 'money'],
        ],
        'schedule' => [],
    ])->assertSessionHasErrors(['charges.0.line_index', 'charges.1.payee']);

    ($this->store)([
        'lines' => [
            ['product_id' => $this->d->salt->id, 'quantity' => '10', 'unit_cost' => '20', 'discount_type' => 'percent', 'discount_value' => '150'],
            ['product_id' => $this->d->sugar->id, 'quantity' => '10', 'unit_cost' => '20', 'discount_type' => 'amount', 'discount_value' => '500'],
        ],
        'charges' => [],
        'schedule' => [],
    ])->assertSessionHasErrors(['lines.0.discount_value', 'lines.1.discount_value']);

    // The plan cannot promise more than the order is worth.
    ($this->store)(['schedule' => [['due_on' => '2026-10-20', 'amount' => '9000']]])->assertSessionHasErrors(['schedule']);

    // Rounding is a matter of paisa.
    ($this->store)(['rounding' => '-25'])->assertSessionHasErrors(['rounding']);

    // Billing on the page needs the supplier's invoice number.
    ($this->store)(['receive_now' => true, 'bill_now' => true])->assertSessionHasErrors(['bill_number']);

    expect(PurchaseOrder::count())->toBe(0);
});

it('orders in another unit and keeps the quantity in the purchase unit', function () {
    $gram = UnitOfMeasure::create(['business_id' => $this->w->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);

    ($this->store)([
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '2500', 'unit_id' => $gram->id, 'unit_cost' => '0.02', 'entry_mode' => 'rate']],
        'charges' => [], 'schedule' => [], 'discount_type' => '', 'discount_value' => '', 'rounding' => '',
    ])->assertSessionHasNoErrors();

    $line = PurchaseOrder::query()->sole()->lines()->sole();

    expect((string) $line->quantity_ordered)->toBe('2.5000')
        ->and((string) $line->entered_quantity)->toBe('2500.0000')
        ->and($line->unit_id)->toBe($gram->id)
        ->and($line->line_total_paisa)->toBe(5000)
        ->and((string) $line->unit_cost)->toBe('20.000000');
});

it('edits an order while nothing has happened to it, and not after the goods arrive', function () {
    ($this->store)()->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();

    ($this->as)()->get(route('purchasing.purchase-orders.edit', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/form')
            ->where('order.po_number', $order->po_number)
            ->where('order.lines.0.discount_value', '10')
            ->where('order.charges.1.payee', 'money'));

    ($this->as)()->put(route('purchasing.purchase-orders.update', $order), ($this->payload)([
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '200', 'unit_cost' => '20']],
        'charges' => [], 'schedule' => [], 'discount_type' => '', 'discount_value' => '', 'rounding' => '',
        // ⭐ Corrections wave 1: an approved order is changed with a reason.
        'reason_text' => 'Supplier changed the pack size',
    ]))->assertSessionHasNoErrors();

    $order = $order->fresh(['lines', 'charges']);
    expect($order->po_number)->toBe('PO-000001')
        ->and($order->lines)->toHaveCount(1)
        ->and($order->charges)->toHaveCount(0)
        ->and($order->grand_total_paisa)->toBe(400000);

    ($this->receiveAll)($order);

    ($this->as)()->get(route('purchasing.purchase-orders.edit', $order))->assertRedirect(route('purchasing.purchase-orders.show', $order));
    ($this->as)()->put(route('purchasing.purchase-orders.update', $order), ($this->payload)(['reason_text' => 'One more change after receipt']))->assertSessionHasErrors(['lines']);
});

it('still shows an order saved before the full page, from its lines', function () {
    $order = app(CreatePurchaseOrder::class)->handle($this->supplier, $this->d->warehouse, [['product' => $this->d->salt, 'quantity' => '10', 'unit_cost' => '20']], $this->w->owner);

    // As the migration left an order nobody had totals for.
    DB::table('purchase_orders')->update(['goods_paisa' => 0, 'grand_total_paisa' => 0, 'discount_paisa' => 0]);
    DB::table('purchase_order_lines')->update(['gross_paisa' => 0, 'entered_quantity' => null, 'entered_rate' => null, 'unit_id' => null]);

    ($this->as)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('full.grand_total', '200.00')
            ->where('full.goods', '200.00')
            ->where('full.lines.0.gross', '200.00')
            ->has('full.charges', 0));

    ($this->as)()->get(route('purchasing.purchase-orders.print', $order))
        ->assertInertia(fn (Assert $page) => $page->where('total', '200.00'));

    // And the old one-line request still makes an order.
    ($this->as)()->post(route('purchasing.purchase-orders.store'), [
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->d->warehouse->id,
        'order_date' => '2026-10-17',
        'lines' => [['product_id' => $this->d->salt->id, 'quantity' => '5', 'unit_cost' => '20']],
    ])->assertSessionHasNoErrors();

    expect(PurchaseOrder::query()->latest('id')->first()->grand_total_paisa)->toBe(10000);
});
