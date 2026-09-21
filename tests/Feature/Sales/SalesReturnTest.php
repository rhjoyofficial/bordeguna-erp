<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales returns: goods that left on a challan come back.
 *
 * ⚠⚠ The GDNI case is the NORMAL case (a distributor rejecting cartons on
 * arrival returns goods that are in GDNI, not in COGS), the money is DERIVED
 * uninvoiced-first (C-SR-4), stock restores onto the very batches the sale
 * consumed newest-first (C-SR-2), and one line may split between back-to-stock
 * and write-off (C-SR-3). See SALES-RETURNS-ARCHITECTURE.md.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Returns Co', 'slug' => 'returns-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR',
    ]);
    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        // A dealer, not a distributor: since 21 Sep 2026 a distributor's order
        // waits for payment before approval, which is not what this file tests.
        'type' => CustomerType::Dealer,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Cash in Hand',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-08-12 10:00:00');

    // ⚠ Through the OPENING STOCK document, not the RecordStockIn primitive:
    // the primitive posts nothing, so Stock on Hand's GL would disagree with
    // the movements and every reconciliation check here would report exactly
    // that. The fixture must enter stock the way production does.
    $this->stockIn = fn (string $qty, string $cost, string $on = '2026-08-01 09:00:00') => app(RecordOpeningStock::class)
        ->handle($this->warehouse, [[
            'product' => $this->product,
            'quantity' => $qty,
            'unitCost' => $cost,
        ]], $this->user, asAt: $on);

    $this->deliver = fn (string $qty) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->user,
    );

    $this->invoiceLine = fn ($deliveryLine, string $qty, string $price, string $discount = '0') => app(RecordSalesInvoice::class)
        ->handle(
            customer: $this->customer,
            lines: [['deliveryLine' => $deliveryLine, 'quantity' => $qty, 'unitPrice' => $price]],
            createdBy: $this->user,
            invoiceDiscount: $discount,
        );

    $this->returnGoods = fn ($delivery, string $toStock, string $writeOff = '0') => app(RecordSalesReturn::class)
        ->handle(
            delivery: $delivery,
            lines: [[
                'deliveryLine' => $delivery->lines->first(),
                'quantityToStock' => $toStock,
                'quantityWrittenOff' => $writeOff,
            ]],
            createdBy: $this->user,
        );

    $this->journalOf = fn (SalesReturn $return) => Journal::where('source_type', $return->getMorphClass())
        ->where('source_id', $return->id)->first();

    $this->linesOf = fn (Journal $journal) => JournalLine::where('journal_id', $journal->id)->get();

    $this->acct = fn (string $slug) => Account::bySlug($slug)->id;

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', ($this->acct)($slug))->sum('amount_minor');

    $this->reconciles = function () {
        $result = SubledgerReconciliation::reconcileAllBusinesses();
        expect($result['problems'])->toBeEmpty();
    };
});

afterEach(fn () => Carbon::setTestNow());

// ─── The GDNI case: the NORMAL case ─────────────────────────────────────────

it('returns uninvoiced goods to stock and reverses the delivery journal only', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    $return = ($this->returnGoods)($delivery, '4');

    expect($return->return_number)->toStartWith('SRN-')
        ->and((float) $return->credit_total)->toBe(0.0);

    // Dr Stock 2000 / Cr GDNI 2000 - the exact mirror of the delivery posting,
    // and no revenue side at all: nothing was ever billed.
    $journal = ($this->journalOf)($return);
    $lines = ($this->linesOf)($journal);

    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('account_id', ($this->acct)('stock_on_hand'))->amount_minor)->toBe(200000)
        ->and($lines->firstWhere('account_id', ($this->acct)('goods_delivered_not_invoiced'))->amount_minor)->toBe(-200000);

    // Month end now bills only what stayed delivered (C-SR-4).
    $line = $delivery->lines->first()->fresh();
    expect($line->quantityUninvoiced())->toBe('6.0000')
        ->and((float) $line->quantity_returned)->toBe(4.0)
        ->and((float) $line->quantity_returned_uninvoiced)->toBe(4.0);

    ($this->reconciles)();
});

it('puts the goods back on the shelf, physically', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    ($this->returnGoods)($delivery, '4');

    $batch = StockBatch::first();
    expect((float) $batch->current_quantity)->toBe(14.0);

    // The IN movement states what it undoes (§10 auditability).
    $restoration = StockMovement::where('reason', MovementReason::SalesReturn)->first();
    expect($restoration->direction)->toBe(MovementDirection::In)
        ->and($restoration->reverses_movement_id)->not->toBeNull();
});

// ─── The invoiced case: credit at the price they actually paid ──────────────

it('credits an invoiced return against the invoice at the invoiced price, gross to 4200', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');
    $invoice = ($this->invoiceLine)($delivery->lines->first(), '10', '850');

    $return = ($this->returnGoods)($delivery, '4');

    expect((float) $return->credit_total)->toBe(3400.0);

    $journal = ($this->journalOf)($return);
    $lines = ($this->linesOf)($journal);

    // Dr Stock 2000, Dr 4200 3400 / Cr COGS 2000, Cr AR 3400.
    expect($lines->firstWhere('account_id', ($this->acct)('stock_on_hand'))->amount_minor)->toBe(200000)
        ->and($lines->firstWhere('account_id', ($this->acct)('sales_returns'))->amount_minor)->toBe(340000)
        ->and($lines->firstWhere('account_id', ($this->acct)('cost_of_goods_sold'))->amount_minor)->toBe(-200000)
        ->and($lines->firstWhere('account_id', ($this->acct)('accounts_receivable'))->amount_minor)->toBe(-340000);

    // The credit applied itself to the invoice it came from.
    expect((float) $invoice->fresh()->outstanding()->toTaka())->toBe(5100.0)
        ->and($return->allocations)->toHaveCount(1)
        ->and((float) $return->allocations->first()->amount)->toBe(3400.0);

    ($this->reconciles)();
});

it('relieves the uninvoiced portion first, then credits the invoiced remainder', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');
    ($this->invoiceLine)($delivery->lines->first(), '4', '850');

    // 8 back: 6 were unbilled (relieved from GDNI), 2 were billed (credited).
    $return = ($this->returnGoods)($delivery, '8');
    $line = $return->lines->first();

    expect((float) $line->gdni_relief)->toBe(3000.0)   // remainder arm: 5000 - 2000 released
        ->and((float) $line->cogs_relief)->toBe(1000.0) // 4000 restored - 3000 GDNI
        ->and((float) $line->credit_net)->toBe(1700.0)  // 2 × 850
        ->and($delivery->lines->first()->fresh()->quantityUninvoiced())->toBe('0.0000');

    // GDNI is EMPTY for this line: delivered 5000, invoiced out 2000, returned
    // out 3000. A bridge that clears exactly is the whole point of the
    // remainder discipline.
    expect(($this->balance)('goods_delivered_not_invoiced'))->toBe(0);

    ($this->reconciles)();
});

// ─── The split (C-SR-3): back to stock vs written off ───────────────────────

it('splits one line between back-to-stock and write-off', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    $return = ($this->returnGoods)($delivery, '3', '2');
    $line = $return->lines->first();

    expect((float) $line->cost_restored)->toBe(1500.0)
        ->and((float) $line->cost_written_off)->toBe(1000.0);

    // Only the sellable 3 re-entered stock; the destroyed 2 never did.
    expect((float) StockBatch::first()->current_quantity)->toBe(13.0);

    // The write-off cost reads as the same kind of loss as shelf damage
    // (C-SR-7): the damaged-goods adjustment account.
    $journal = ($this->journalOf)($return);
    $lines = ($this->linesOf)($journal);

    expect($lines->firstWhere('account_id', ($this->acct)('stock_on_hand'))->amount_minor)->toBe(150000)
        ->and($lines->firstWhere('account_id', ($this->acct)('inventory_write_off'))->amount_minor)->toBe(100000)
        ->and($lines->firstWhere('account_id', ($this->acct)('goods_delivered_not_invoiced'))->amount_minor)->toBe(-250000);

    ($this->reconciles)();
});

it('never restores a layer a write-off already claimed - the capacity ledger', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    // 5 destroyed on inspection, then the other 5 come back sellable.
    ($this->returnGoods)($delivery, '0', '5');
    ($this->returnGoods)($delivery, '5');

    // 10 delivered: 5 destroyed (never restock), 5 restored. 20 - 10 + 5 = 15.
    expect((float) StockBatch::first()->current_quantity)->toBe(15.0);

    // And nothing more can come back.
    expect(fn () => ($this->returnGoods)($delivery, '1'))
        ->toThrow(ValidationException::class);

    ($this->reconciles)();
});

// ─── Reverse-order restoration across batches (C-SR-2) ──────────────────────

it('restores onto the batches the sale consumed, newest-consumed first', function () {
    // Two layers at two costs in one opening document - opening stock is
    // once-per-warehouse, and two lines make two batches.
    app(RecordOpeningStock::class)->handle($this->warehouse, [
        ['product' => $this->product, 'quantity' => '10', 'unitCost' => '500'],
        ['product' => $this->product, 'quantity' => '10', 'unitCost' => '600'],
    ], $this->user, asAt: '2026-08-01 09:00:00');

    $delivery = ($this->deliver)('15'); // FIFO: all of A(500), 5 of B(600)

    ($this->returnGoods)($delivery, '6');

    // Reverse order: B gets its 5 back first, then A gets 1 - so what remains
    // consumed (9 of A) is still a FIFO prefix.
    $batches = StockBatch::orderBy('id')->get();
    expect((float) $batches[0]->current_quantity)->toBe(1.0)
        ->and((float) $batches[1]->current_quantity)->toBe(10.0);

    // Valued at the layers restored: in 11000, out 10×500 + 5×600 = 8000,
    // back 5×600 + 1×500 = 3500.
    expect(($this->balance)('stock_on_hand'))->toBe(650000);

    ($this->reconciles)();
});

// ─── Caps and refusals ──────────────────────────────────────────────────────

it('refuses to return more than was delivered', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    expect(fn () => ($this->returnGoods)($delivery, '11'))
        ->toThrow(ValidationException::class);
});

it('refuses a second return that would exceed the delivery', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    ($this->returnGoods)($delivery, '7');

    expect(fn () => ($this->returnGoods)($delivery, '4'))
        ->toThrow(ValidationException::class);
});

it('refuses a line from a different delivery', function () {
    ($this->stockIn)('40', '500');
    $first = ($this->deliver)('10');
    $second = ($this->deliver)('10');

    expect(fn () => app(RecordSalesReturn::class)->handle(
        delivery: $first,
        lines: [[
            'deliveryLine' => $second->lines->first(),
            'quantityToStock' => '2',
            'quantityWrittenOff' => '0',
        ]],
        createdBy: $this->user,
    ))->toThrow(ValidationException::class);
});

// ─── Billing after a return ─────────────────────────────────────────────────

it('bills the net after an uninvoiced return, and GDNI clears to zero', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    ($this->returnGoods)($delivery, '4'); // pro-rata relief: 2000
    ($this->invoiceLine)($delivery->lines->first(), '6', '850'); // remainder: 5000 - 2000 = 3000

    expect(($this->balance)('goods_delivered_not_invoiced'))->toBe(0)
        ->and(($this->balance)('cost_of_goods_sold'))->toBe(300000);

    // Billing anything further is refused - nothing is left unbilled.
    expect(fn () => ($this->invoiceLine)($delivery->lines->first(), '1', '850'))
        ->toThrow(ValidationException::class);

    ($this->reconciles)();
});

// ─── The invoice-level discount clawback ────────────────────────────────────

it('claws back the pro-rated invoice discount so the customer is credited what they paid', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');
    $invoice = ($this->invoiceLine)($delivery->lines->first(), '10', '850', '850'); // 10% off the bill

    $return = ($this->returnGoods)($delivery, '5');
    $line = $return->lines->first();

    expect((float) $line->credit_gross)->toBe(4250.0)
        ->and((float) $line->credit_discount)->toBe(425.0)
        ->and((float) $line->credit_net)->toBe(3825.0);

    $journal = ($this->journalOf)($return);
    $lines = ($this->linesOf)($journal);

    expect($lines->firstWhere('account_id', ($this->acct)('sales_returns'))->amount_minor)->toBe(425000)
        ->and($lines->firstWhere('account_id', ($this->acct)('discounts_allowed'))->amount_minor)->toBe(-42500)
        ->and($lines->firstWhere('account_id', ($this->acct)('accounts_receivable'))->amount_minor)->toBe(-382500);

    expect((float) $invoice->fresh()->outstanding()->toTaka())->toBe(3825.0); // 7650 - 3825

    ($this->reconciles)();
});

// ─── Refunds (C-SR-6) ───────────────────────────────────────────────────────

it('pays a refund against the return credit and stops at the cap', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');
    ($this->invoiceLine)($delivery->lines->first(), '10', '850');

    $return = ($this->returnGoods)($delivery, '4'); // credit 3400

    $refund = app(RecordCustomerRefund::class)->handle(
        salesReturn: $return,
        moneyAccount: $this->cash,
        amount: '3000',
        createdBy: $this->user,
    );

    // Dr AR / Cr cash - the exact reverse of a receipt.
    $journal = Journal::where('source_type', $refund->getMorphClass())->where('source_id', $refund->id)->first();
    $lines = ($this->linesOf)($journal);

    expect($lines->firstWhere('account_id', ($this->acct)('accounts_receivable'))->amount_minor)->toBe(300000)
        ->and($lines->firstWhere('account_id', ($this->acct)('cash_in_hand'))->amount_minor)->toBe(-300000);

    expect((float) $return->fresh()->refundable()->toTaka())->toBe(400.0);

    // A refund beyond the remaining credit is refused, not absorbed.
    expect(fn () => app(RecordCustomerRefund::class)->handle(
        salesReturn: $return,
        moneyAccount: $this->cash,
        amount: '500',
        createdBy: $this->user,
    ))->toThrow(ValidationException::class);

    ($this->reconciles)();
});

// ─── What a return must NOT touch ───────────────────────────────────────────

// ⚠ C-SR-8 (Joy): the promise was FULFILLED; the return is a new event. If the
// customer wants the goods again, that is a new order - reopening would make
// the order book oscillate and invite re-delivery against an abandoned order.
it('never reopens the sales order the goods were delivered against', function () {
    Permission::firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('sales.orders.approve');
    $this->user->assignRole('Owner');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850.0000',
        'effective_from' => '2026-08-01',
        'created_by' => $this->user->id,
    ]);

    ($this->stockIn)('20', '500');

    $order = app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => '10']],
        createdBy: $this->user,
    );

    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'orderLine' => $order->lines->first()]],
        createdBy: $this->user,
    );

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Delivered);

    ($this->returnGoods)($delivery, '6');

    // Status untouched, quantity_delivered untouched: the order book does not
    // move when goods come back.
    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::Delivered)
        ->and((float) $order->lines->first()->quantity_delivered)->toBe(10.0);

    ($this->reconciles)();
});

it('posts no journal when accounting is off, but still moves the stock', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    // Switch sales posting off by removing the anchor.
    AccountMapping::where('key', 'sales.revenue')->delete();

    $return = ($this->returnGoods)($delivery, '4');

    expect(($this->journalOf)($return))->toBeNull()
        ->and((float) StockBatch::first()->current_quantity)->toBe(14.0);
});
