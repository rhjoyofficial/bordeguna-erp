<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales slice 2: the goods leaving.
 *
 * ⚠⚠ The half of a sale that moves stock, and the FIRST TIME THIS SYSTEM HAS
 * EVER TAKEN STOCK OUT AT FIFO FOR A CUSTOMER. Everything the ERP did with stock
 * before this put it in at a known price or wrote it off; the cost of the layers
 * consumed here is what becomes cost of goods sold, and therefore margin.
 *
 * ⛔ NO REVENUE HERE. A delivery posts Dr Goods Delivered Not Invoiced /
 * Cr Stock on Hand. Revenue and COGS belong to the invoice (slice 3).
 *
 * See SALES-PHASE1-DESIGN.md §2 and §4.1.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Delivery Co', 'slug' => 'delivery-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
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

    $this->stockIn = function (string $quantity, string $cost, ?string $on = null) {
        return app(RecordStockIn::class)->handle(
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: $quantity,
            unitCost: $cost,
            performedBy: $this->user,
            occurredAt: $on ?? '2026-08-01 09:00:00',
        );
    };

    $this->deliver = function (string $quantity, mixed $on = null) {
        return app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $this->user,
            deliveredAt: $on,
        );
    };
});

afterEach(fn () => Carbon::setTestNow());

it('takes stock out and records what left', function () {
    ($this->stockIn)('100', '500');

    $delivery = ($this->deliver)('30');

    expect($delivery->delivery_number)->toStartWith('DC-')
        ->and($delivery->lines)->toHaveCount(1)
        ->and((float) $delivery->lines->first()->quantity)->toBe(30.0)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('70');
});

it('marks the movement as a sale rather than internal usage', function () {
    ($this->stockIn)('100', '500');
    ($this->deliver)('30');

    $movement = StockMovement::where('reason', MovementReason::Sale)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->direction)->toBe(MovementDirection::Out)
        // ⚠ reason_id is a FK to `reasons` and must stay null here. Writing a
        // delivery-line id into it would point at an unrelated Reason row.
        ->and($movement->reason_id)->toBeNull();
});

// ⚠⚠ R2 of the review, and the reason delivery_line_movements exists: one line
// at FIFO consumes several batches and writes several movements at several
// costs. The cost of the sale is the SUM of the layers actually taken.
it('consumes several batches oldest first and links every movement to its line', function () {
    ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
    ($this->stockIn)('10', '600', '2026-08-02 09:00:00');

    $delivery = ($this->deliver)('15');
    $line = $delivery->lines->first();

    expect($line->movements)->toHaveCount(2);

    // 10 at 500 then 5 at 600 = 5000 + 3000 = 8000.
    // ⚠ Money is stored in paisa and formats to two places; quantities and unit
    // costs carry four. The narrowing happens once, here, where value becomes
    // money - not repeatedly down the chain.
    expect($line->costValue()->toTaka())->toBe('8000.00');
});

it('refuses to deliver more than the warehouse holds', function () {
    ($this->stockIn)('5', '500');

    ($this->deliver)('6');
})->throws(InsufficientStockException::class);

// ⚠ All or nothing. A refused delivery must leave no document and no stock
// movement behind, or the warehouse and the paperwork disagree.
it('leaves nothing behind when a line cannot be fulfilled', function () {
    ($this->stockIn)('5', '500');

    try {
        app(RecordDelivery::class)->handle(
            customer: $this->customer,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '99']],
            createdBy: $this->user,
        );
    } catch (InsufficientStockException) {
        // expected
    }

    expect(Delivery::count())->toBe(0)
        ->and(StockMovement::where('reason', MovementReason::Sale)->count())->toBe(0)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('5');
});

it('refuses a service, which has no stock to deliver', function () {
    $service = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->product->unit_of_measure_id,
        'sku' => 'SKU-DELIVERY-FEE',
        'name' => 'Delivery Charge',
        'type' => ProductType::Service,
    ]);

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $service, 'quantity' => '1']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class, 'cannot be delivered');

it('refuses a delivery with no lines', function () {
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '0']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class);

// ⚠ A delivery dated into a closed month would change a figure somebody has
// already reported.
it('refuses to deliver into a closed accounting period', function () {
    ($this->stockIn)('100', '500', '2026-07-01 09:00:00');
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user);

    ($this->deliver)('10', '2026-07-15 10:00:00');
})->throws(PeriodClosedException::class);

it('numbers challans sequentially within the business', function () {
    ($this->stockIn)('100', '500');

    $first = ($this->deliver)('1');
    $second = ($this->deliver)('1');

    expect($second->delivery_number)->not->toBe($first->delivery_number)
        ->and((int) substr($second->delivery_number, -4))
        ->toBe((int) substr($first->delivery_number, -4) + 1);
});

describe('the ledger', function () {
    // ⚠⚠ THE MIRROR OF GRNI. Cost sits in Goods Delivered Not Invoiced until the
    // invoice releases it, so cost and revenue land in the SAME period even when
    // the challan and the invoice fall in different months.
    it('debits Goods Delivered Not Invoiced and credits Stock on Hand, at FIFO cost', function () {
        ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
        ($this->stockIn)('10', '600', '2026-08-02 09:00:00');

        $delivery = ($this->deliver)('15');

        $journal = Journal::where('source_type', $delivery->getMorphClass())
            ->where('source_id', $delivery->id)->first();

        expect($journal)->not->toBeNull();

        $gdni = Account::bySlug('goods_delivered_not_invoiced');
        $stock = Account::bySlug('stock_on_hand');

        // ⚠ `amount_minor`, signed whole paisa: positive debits, negative
        // credits. There is no `amount` column, and `->amount` collides with a
        // method - an assertion against it silently matched nothing and the
        // test passed on an empty set.
        $gdniLines = JournalLine::where('journal_id', $journal->id)->where('account_id', $gdni->id)->get();
        $stockLines = JournalLine::where('journal_id', $journal->id)->where('account_id', $stock->id)->get();

        // ⚠⚠ Non-emptiness asserted FIRST. A sum over no rows is zero, which is
        // exactly what a broken posting looks like.
        expect($gdniLines)->not->toBeEmpty()
            ->and($stockLines)->not->toBeEmpty();

        // 10 at 500 + 5 at 600 = 8000 taka = 800000 paisa, debited to GDNI and
        // credited out of stock. Two credit lines, one per FIFO layer.
        expect($stockLines)->toHaveCount(2)
            ->and((int) $gdniLines->sum('amount_minor'))->toBe(800000)
            ->and((int) $stockLines->sum('amount_minor'))->toBe(-800000);
    });

    it('balances to zero', function () {
        ($this->stockIn)('10', '500', '2026-08-01 09:00:00');
        ($this->stockIn)('10', '333.3333', '2026-08-02 09:00:00');

        $delivery = ($this->deliver)('17');
        $journal = Journal::where('source_id', $delivery->id)
            ->where('source_type', $delivery->getMorphClass())->first();

        $lines = JournalLine::where('journal_id', $journal->id)->get();

        // ⚠ An empty journal also sums to zero. Assert it exists first.
        expect($lines)->not->toBeEmpty()
            ->and((int) $lines->sum('amount_minor'))->toBe(0);
    });

    // ⚠ Posts NO revenue. Revenue belongs to the invoice, and a delivery that
    // credited Sales Revenue would recognise income for goods nobody has been
    // billed for.
    it('records no revenue and no cost of goods sold', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('5');

        $journal = Journal::where('source_id', $delivery->id)
            ->where('source_type', $delivery->getMorphClass())->first();

        $touched = JournalLine::where('journal_id', $journal->id)->pluck('account_id');

        // ⚠ An empty journal touches no revenue account either. Prove the
        // journal is real before proving what it left alone.
        expect($touched)->not->toBeEmpty()
            ->and($touched)->not->toContain(Account::bySlug('sales_revenue')->id)
            ->and($touched)->not->toContain(Account::bySlug('cost_of_goods_sold')->id);
    });

    it('names the customer on the receivable side, so the balance is answerable', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('5');

        $journal = Journal::where('source_id', $delivery->id)
            ->where('source_type', $delivery->getMorphClass())->first();

        $gdniLine = JournalLine::where('journal_id', $journal->id)
            ->where('account_id', Account::bySlug('goods_delivered_not_invoiced')->id)->first();

        expect($gdniLine->party_type)->toBe($this->customer->getMorphClass())
            ->and($gdniLine->party_id)->toBe($this->customer->id);
    });

    // ⚠⚠ THE STANDING RULE: an accounting feature must never be able to stop an
    // operational process. A shop whose ledger is not configured must still be
    // able to send goods to a customer.
    it('still delivers when sales posting is not configured', function () {
        AccountMapping::where('key', 'sales.revenue')->delete();

        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('4');

        expect($delivery->exists)->toBeTrue()
            ->and($this->product->fresh()->currentStockQuantity())->toBe('6')
            ->and(Journal::where('source_id', $delivery->id)
                ->where('source_type', $delivery->getMorphClass())->count())->toBe(0);
    });

    it('does not post the same delivery twice', function () {
        ($this->stockIn)('10', '500');
        $delivery = ($this->deliver)('4');

        app(\App\Modules\Sales\Actions\PostSalesJournals::class)->forDelivery($delivery, $this->user);

        expect(Journal::where('source_id', $delivery->id)
            ->where('source_type', $delivery->getMorphClass())->count())->toBe(1);
    });
});

it('never delivers from another business warehouse', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-delivery-co']);
    $theirWarehouse = Warehouse::create([
        'business_id' => $other->id, 'name' => 'Theirs', 'code' => 'THEIRS',
    ]);

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $theirWarehouse,
        lines: [['product' => $this->product, 'quantity' => '1']],
        createdBy: $this->user,
    );
})->throws(ValidationException::class, 'different business');
