<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales slice 3: the revenue, and the cost of it.
 *
 * ⚠⚠ THE INVOICE IS WHERE THE BUSINESS EARNS SOMETHING. It also releases the
 * cost of the goods from Goods Delivered Not Invoiced into Cost of Goods Sold,
 * so a challan raised in July and invoiced in August puts its cost in August,
 * beside the revenue it earned. That is the whole reason GDNI exists.
 *
 * See SALES-PHASE1-DESIGN.md §4.2.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Invoice Co', 'slug' => 'invoice-co']);
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
        'type' => CustomerType::Distributor,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-08-04 10:00:00');

    $this->stockIn = fn (string $qty, string $cost, string $on = '2026-08-01 09:00:00') => app(RecordStockIn::class)
        ->handle($this->product, $this->warehouse, $qty, $cost, $this->user, occurredAt: $on);

    $this->deliver = fn (string $qty, mixed $on = null) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->user,
        deliveredAt: $on,
    );

    $this->invoiceFor = fn (array $lines, string $discount = '0', mixed $on = null) => app(RecordSalesInvoice::class)
        ->handle(
            customer: $this->customer,
            lines: $lines,
            createdBy: $this->user,
            invoiceDate: $on,
            invoiceDiscount: $discount,
        );

    $this->journalOf = fn (SalesInvoice $invoice) => Journal::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->first();

    $this->linesOf = fn (Journal $journal) => JournalLine::where('journal_id', $journal->id)->get();

    $this->acct = fn (string $slug) => Account::bySlug($slug)->id;
});

afterEach(fn () => Carbon::setTestNow());

it('bills a delivery and totals the invoice', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    $invoice = ($this->invoiceFor)([[
        'deliveryLine' => $delivery->lines->first(),
        'quantity' => '10',
        'unitPrice' => '850',
    ]]);

    expect($invoice->invoice_number)->toStartWith('INV-')
        ->and((float) $invoice->subtotal)->toBe(8500.0)
        ->and((float) $invoice->total)->toBe(8500.0)
        ->and((float) $invoice->tax_total)->toBe(0.0);
});

it('marks the delivered quantity as invoiced', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');
    $line = $delivery->lines->first();

    ($this->invoiceFor)([['deliveryLine' => $line, 'quantity' => '4', 'unitPrice' => '850']]);

    expect((float) $line->fresh()->quantity_invoiced)->toBe(4.0)
        ->and($line->fresh()->quantityUninvoiced())->toBe('6.0000');
});

it('refuses to bill more than was delivered', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    ($this->invoiceFor)([['deliveryLine' => $delivery->lines->first(), 'quantity' => '11', 'unitPrice' => '850']]);
})->throws(ValidationException::class, 'still uninvoiced');

it('refuses to bill a delivery belonging to another customer', function () {
    ($this->stockIn)('20', '500');
    $delivery = ($this->deliver)('10');

    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Someone Else']);

    app(RecordSalesInvoice::class)->handle(
        customer: $other,
        lines: [['deliveryLine' => $delivery->lines->first(), 'quantity' => '1', 'unitPrice' => '850']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class, 'different customer');

describe('discounts', function () {
    // ⚠ A line discount reduces the line. Sales Revenue is credited NET, because
    // it is a decision about what that product was worth to this customer.
    it('takes a line discount off revenue directly', function () {
        ($this->stockIn)('20', '500');
        $delivery = ($this->deliver)('10');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '10',
            'unitPrice' => '850',
            'discount' => '500',
        ]]);

        $lines = ($this->linesOf)(($this->journalOf)($invoice));

        expect((float) $invoice->subtotal)->toBe(8000.0)
            ->and((int) $lines->where('account_id', ($this->acct)('sales_revenue'))->sum('amount_minor'))->toBe(-800000)
            ->and($lines->pluck('account_id'))->not->toContain(($this->acct)('discounts_allowed'));
    });

    // ⚠⚠ An invoice discount is CONTRA-REVENUE, posted separately, because it is
    // a decision about the whole bill rather than about what a product is worth.
    // Revenue stays at the full subtotal and the discount is visible.
    it('posts an invoice discount to Discounts Allowed, leaving revenue whole', function () {
        ($this->stockIn)('20', '500');
        $delivery = ($this->deliver)('10');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '10',
            'unitPrice' => '850',
        ]], '500');

        $lines = ($this->linesOf)(($this->journalOf)($invoice));

        expect((float) $invoice->subtotal)->toBe(8500.0)
            ->and((float) $invoice->total)->toBe(8000.0)
            ->and((int) $lines->where('account_id', ($this->acct)('sales_revenue'))->sum('amount_minor'))->toBe(-850000)
            ->and((int) $lines->where('account_id', ($this->acct)('discounts_allowed'))->sum('amount_minor'))->toBe(50000)
            ->and((int) $lines->where('account_id', ($this->acct)('accounts_receivable'))->sum('amount_minor'))->toBe(800000);
    });

    it('refuses a discount larger than the bill', function () {
        ($this->stockIn)('20', '500');
        $delivery = ($this->deliver)('10');

        ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '1',
            'unitPrice' => '100',
        ]], '500');
    })->throws(ValidationException::class, 'cannot exceed the invoice subtotal');

    it('refuses a line discount larger than the line', function () {
        ($this->stockIn)('20', '500');
        $delivery = ($this->deliver)('10');

        ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '1',
            'unitPrice' => '100',
            'discount' => '500',
        ]]);
    })->throws(ValidationException::class, 'cannot exceed the line total');
});

describe('the cost of goods sold', function () {
    // ⚠⚠ THE ARITHMETIC EVERY MARGIN THIS BUSINESS EVER REPORTS DEPENDS ON.
    // 10 at 500 then 5 at 600 = 8000 cost; sold at 850 each = 12750 revenue.
    it('releases the FIFO cost from GDNI into Cost of Goods Sold', function () {
        ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
        ($this->stockIn)('10', '600', '2026-08-02 09:00:00');
        $delivery = ($this->deliver)('15');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '15',
            'unitPrice' => '850',
        ]]);

        $lines = ($this->linesOf)(($this->journalOf)($invoice));

        expect($lines)->not->toBeEmpty()
            ->and((int) $lines->where('account_id', ($this->acct)('cost_of_goods_sold'))->sum('amount_minor'))->toBe(800000)
            ->and((int) $lines->where('account_id', ($this->acct)('goods_delivered_not_invoiced'))->sum('amount_minor'))->toBe(-800000)
            ->and((int) $lines->where('account_id', ($this->acct)('sales_revenue'))->sum('amount_minor'))->toBe(-1275000);
    });

    // ⚠⚠ GDNI MUST END AT ZERO once a challan is fully billed. A bridge account
    // that never quite clears is indistinguishable from a real unbilled balance.
    it('leaves GDNI at exactly zero when the whole challan is invoiced', function () {
        ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
        ($this->stockIn)('10', '600', '2026-08-02 09:00:00');
        $delivery = ($this->deliver)('15');

        ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '15',
            'unitPrice' => '850',
        ]]);

        $balance = JournalLine::where('account_id', ($this->acct)('goods_delivered_not_invoiced'))->sum('amount_minor');

        expect((int) $balance)->toBe(0);
    });

    // ⚠⚠ THE ROUNDING CASE. A cost that does not divide evenly, billed in three
    // parts: pro-rata fractions do not sum back to the whole, so the LAST
    // portion must release the remainder rather than another rounded fraction.
    it('clears GDNI to zero even when the cost does not divide evenly', function () {
        ($this->stockIn)('3', '333.3333');
        $delivery = ($this->deliver)('3');
        $line = $delivery->lines->first();

        ($this->invoiceFor)([['deliveryLine' => $line, 'quantity' => '1', 'unitPrice' => '500']]);
        ($this->invoiceFor)([['deliveryLine' => $line->fresh(), 'quantity' => '1', 'unitPrice' => '500']]);
        ($this->invoiceFor)([['deliveryLine' => $line->fresh(), 'quantity' => '1', 'unitPrice' => '500']]);

        $balance = JournalLine::where('account_id', ($this->acct)('goods_delivered_not_invoiced'))->sum('amount_minor');

        expect((int) $balance)->toBe(0)
            ->and((float) $line->fresh()->quantity_invoiced)->toBe(3.0);
    });

    it('releases cost in proportion when only part of a challan is billed', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('10');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '4',
            'unitPrice' => '850',
        ]]);

        // 4 of 10 at 500 = 2000 released, 3000 still sitting in GDNI.
        expect($invoice->costReleased()->toTaka())->toBe('2000.00');

        $balance = JournalLine::where('account_id', ($this->acct)('goods_delivered_not_invoiced'))->sum('amount_minor');
        expect((int) $balance)->toBe(300000);
    });

    // ⚠ A service has no stock and therefore no cost of goods sold.
    it('posts no cost for a service line', function () {
        $service = Product::create([
            'business_id' => $this->business->id,
            'unit_of_measure_id' => $this->unit->id,
            'sku' => 'SKU-FEE',
            'name' => 'Delivery Charge',
            'type' => ProductType::Service,
        ]);

        $invoice = ($this->invoiceFor)([[
            'product' => $service,
            'description' => 'Delivery charge',
            'quantity' => '1',
            'unitPrice' => '200',
        ]]);

        $lines = ($this->linesOf)(($this->journalOf)($invoice));

        expect($lines)->not->toBeEmpty()
            ->and($lines->pluck('account_id'))->not->toContain(($this->acct)('cost_of_goods_sold'))
            ->and((int) $lines->where('account_id', ($this->acct)('sales_revenue'))->sum('amount_minor'))->toBe(-20000);
    });
});

describe('the ledger', function () {
    it('balances to zero', function () {
        ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
        ($this->stockIn)('10', '333.3333', '2026-08-02 09:00:00');
        $delivery = ($this->deliver)('17');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '17',
            'unitPrice' => '777.77',
            'discount' => '13.33',
        ]], '7.77');

        $lines = ($this->linesOf)(($this->journalOf)($invoice));

        expect($lines)->not->toBeEmpty()
            ->and((int) $lines->sum('amount_minor'))->toBe(0);
    });

    // ⚠ AR is debited even at a counter. The receipt credits it in the same
    // transaction and it nets to zero - which is what makes retail and credit
    // one engine instead of two.
    it('names the customer on the receivable, so the balance is answerable', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('5');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '5',
            'unitPrice' => '850',
        ]]);

        $arLine = ($this->linesOf)(($this->journalOf)($invoice))
            ->firstWhere('account_id', ($this->acct)('accounts_receivable'));

        expect($arLine)->not->toBeNull()
            ->and($arLine->party_type)->toBe($this->customer->getMorphClass())
            ->and($arLine->party_id)->toBe($this->customer->id);
    });

    it('still invoices when sales posting is not configured', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('5');

        \App\Modules\Accounting\Models\AccountMapping::where('key', 'sales.revenue')->delete();

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '5',
            'unitPrice' => '850',
        ]]);

        expect($invoice->exists)->toBeTrue()
            ->and((float) $invoice->total)->toBe(4250.0)
            ->and(($this->journalOf)($invoice))->toBeNull();
    });

    it('does not post the same invoice twice', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('5');

        $invoice = ($this->invoiceFor)([[
            'deliveryLine' => $delivery->lines->first(),
            'quantity' => '5',
            'unitPrice' => '850',
        ]]);

        app(\App\Modules\Sales\Actions\PostSalesJournals::class)->forInvoice($invoice, $this->user);

        expect(Journal::where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)->count())->toBe(1);
    });
});

// ⚠⚠ THE POINT OF THE WHOLE DESIGN. Goods leave in July, the invoice goes out
// in August, and BOTH the revenue and its cost land in August. Under the
// obvious design the cost would have landed in July and every margin would be
// drawn from two different periods.
it('keeps cost and revenue in the same month when the challan and invoice differ', function () {
    ($this->stockIn)('10', '500', '2026-07-05 09:00:00');
    $delivery = ($this->deliver)('10', '2026-07-20 10:00:00');

    $invoice = ($this->invoiceFor)([[
        'deliveryLine' => $delivery->lines->first(),
        'quantity' => '10',
        'unitPrice' => '850',
    ]], '0', '2026-08-03');

    $deliveryJournal = Journal::where('source_type', $delivery->getMorphClass())
        ->where('source_id', $delivery->id)->first();
    $invoiceJournal = ($this->journalOf)($invoice);

    expect(Carbon::parse($deliveryJournal->journal_date)->toDateString())->toBe('2026-07-20')
        ->and(Carbon::parse($invoiceJournal->journal_date)->toDateString())->toBe('2026-08-03');

    $cogsLine = ($this->linesOf)($invoiceJournal)->firstWhere('account_id', ($this->acct)('cost_of_goods_sold'));
    $revenueLine = ($this->linesOf)($invoiceJournal)->firstWhere('account_id', ($this->acct)('sales_revenue'));

    // ⚠ Both in August, on the same journal, on the same date.
    expect($cogsLine)->not->toBeNull()
        ->and($revenueLine)->not->toBeNull()
        ->and((int) $cogsLine->amount_minor)->toBe(500000)
        ->and((int) $revenueLine->amount_minor)->toBe(-850000);
});
