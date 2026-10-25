<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Inventory\Actions\MoveBatchToWarehouse;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscountWorld;

/**
 * ⭐ 26 Oct 2026: every batch carries its exact value (the live defect of
 * 25 Oct: "the stock batch cache differs from the movements by 0.04").
 *
 * The live shape: a landed cost that does not divide evenly over the units.
 * 1,000 kg of salt at Tk 1.00 plus Tk 3.33 freight is worth exactly 1,003.33;
 * the unit cost is 1.0033 (4 places) and 1,000 x 1.0033 is only 1,003.30.
 * Before this, the cache said 1,003.30 against movements of 1,003.33, and the
 * goods left at 1.0033 a kg, so the batch sold out still worth 0.03.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;

    $order = $this->d->order([[$this->d->salt, '1000', '1.00']]);
    $receipt = $order->goodsReceipts()->with('lines')->latest('id')->firstOrFail();
    $lc = app(SaveLandedCost::class)->handle($receipt, '2026-10-11', [[
        'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => '3.33', 'supplier_id' => $this->d->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Van from the port',
    ]], $this->w->owner);
    app(PostLandedCost::class)->handle(app(SubmitLandedCost::class)->handle($lc, $this->w->owner)->fresh(['approvalRequest', 'lines']), $this->w->owner);

    $this->batch = fn (): StockBatch => StockBatch::query()->where('product_id', $this->d->salt->id)->where('warehouse_id', $this->d->warehouse->id)->sole();

    // The stock side of a sale (or a return), posted through the engine at
    // what the movements moved, signed the way stock moved.
    $this->post = function (Collection $movements, string $on, string $memo) {
        $value = StockValue::sum($movements);
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($on)->startOfDay(),
            lines: [JournalLineDraft::signed(Account::bySlug('stock_on_hand')->id, $value), JournalLineDraft::signed(Account::bySlug('cost_of_goods_sold')->id, $value->negated())],
            memo: $memo,
            source: $movements->first(),
        ), $this->w->owner);
    };

    $this->sell = function (string $kg, string $on = '2026-10-12') {
        $movements = app(RecordStockOut::class)->handle(product: $this->d->salt, warehouse: $this->d->warehouse, quantity: $kg, performedBy: $this->w->owner, occurredAt: $on);
        ($this->post)($movements, $on, "Test sale of {$kg} kg salt");

        return $movements;
    };

    $this->stockLedger = fn (): int => (int) DB::table('journal_lines')->where('account_id', Account::bySlug('stock_on_hand')->id)->sum('amount_minor');

    // Debits = credits, the stock chain holds (quantity AND value per batch),
    // the ledger equals the batch values, and the reconciliation is clean.
    $this->reconciles = function () {
        $result = SubledgerReconciliation::reconcileCurrentBusiness();
        $stock = fn (array $lines) => collect($lines)->filter(fn (string $l) => str_contains($l, 'Stock on Hand') || str_contains($l, 'stock batch'))->values()->all();

        expect((int) DB::table('journal_lines')->sum('amount_minor'))->toBe(0)
            ->and(StockLedger::verify())->toBe([])
            ->and(($this->stockLedger)())->toBe((int) StockBatch::query()->sum('current_value'))
            ->and($stock($result['problems']))->toBe([])
            ->and($stock($result['warnings']))->toBe([]);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('carries the exact value after a landed cost, so the reconciliation sees no cache difference', function () {
    $batch = ($this->batch)();

    expect((string) $batch->unit_cost)->toBe('1.0033')
        ->and((int) $batch->current_value)->toBe(100_333)
        // The old cache figure, for the record: 3 paisa short.
        ->and(Money::ofProduct((string) $batch->current_quantity, (string) $batch->unit_cost)->minor)->toBe(100_330);

    ($this->reconciles)();
});

it('takes a proportional share on each issue and exactly what is left on the last, so a sold-out batch is worth nothing', function () {
    $first = ($this->sell)('400');
    $second = ($this->sell)('600', '2026-10-13');

    // 100,333 x 400 / 1,000 = 40,133.2 -> 40,133; the rest is 60,200.
    expect($first->sole()->value_minor)->toBe(40_133)
        ->and($second->sole()->value_minor)->toBe(60_200)
        ->and((string) ($this->batch)()->current_quantity)->toBe('0.0000')
        ->and((int) ($this->batch)()->current_value)->toBe(0)
        ->and(($this->stockLedger)())->toBe(0);

    ($this->reconciles)();
});

it('moves the same value with a transfer, to the paisa', function () {
    $shop = Warehouse::create(['business_id' => $this->w->business->id, 'name' => 'Shop', 'code' => 'SHOP']);

    app(MoveBatchToWarehouse::class)->handle(($this->batch)(), $shop, '500', MoveBatchToWarehouse::reasonNamed('Shelf stock'), $this->w->owner, occurredAt: '2026-10-12');

    $legs = StockMovement::query()->where('reason', MovementReason::Transfer)->orderBy('id')->get();
    $arrived = StockBatch::query()->where('warehouse_id', $shop->id)->sole();

    // 100,333 x 500 / 1,000 = 50,166.5 -> 50,167, half away from zero.
    expect($legs->pluck('value_minor')->all())->toBe([50_167, 50_167])
        ->and((int) $arrived->current_value)->toBe(50_167)
        ->and((int) ($this->batch)()->current_value)->toBe(50_166)
        ->and((int) StockBatch::query()->sum('current_value'))->toBe(100_333);

    ($this->reconciles)();
});

it('puts goods back at exactly what they left at', function () {
    $out = ($this->sell)('400')->sole();

    $back = app(RestoreStockIssue::class)->handle([['movement' => $out, 'quantity' => '400']], $this->w->owner, 'Customer returned it', occurredAt: '2026-10-13')->sole();
    ($this->post)(collect([$back]), '2026-10-13', 'Test return');

    expect($back->value_minor)->toBe(40_133)
        ->and((int) ($this->batch)()->current_value)->toBe(100_333);

    ($this->reconciles)();
});

it('splits a part restore in proportion and gives back the rest on the last part', function () {
    $out = ($this->sell)('400')->sole();
    $restore = app(RestoreStockIssue::class);

    $part = $restore->handle([['movement' => $out, 'quantity' => '100']], $this->w->owner, 'Part back', occurredAt: '2026-10-13')->sole();

    // 40,133 x 100 / 400 = 10,033.25 -> 10,033.
    expect($part->value_minor)->toBe(10_033)
        ->and(StockValue::share(40_133, 4_000_000, 4_000_000))->toBe(40_133)
        ->and(StockValue::share(-7, 1, 2))->toBe(-4)
        ->and(StockValue::share(0, 1, 3))->toBe(0);
});

it('works in bcmath for a crore of taka over a large quantity', function () {
    // Tk 1,00,00,000.01 over 99,999.9999 units, 33,333.3333 of them.
    expect(StockValue::share(1_000_000_001, 333_333_333, 999_999_999))->toBe(333_333_334);
});

it('verify-ledger catches a batch value that drifted from its movements, and --rebuild repairs it', function () {
    DB::table('stock_batches')->where('id', ($this->batch)()->id)->update(['current_value' => 100_330]);

    expect(implode(' ', StockLedger::verify()))->toContain('ledger says it is worth');

    // ⚠ A console command runs cold and leaves the tenant: enter it again.
    $this->artisan('inventory:verify-ledger')->assertFailed();
    $this->artisan('inventory:verify-ledger --rebuild')->assertSuccessful();
    $this->w->enter($this->w->business);

    expect((int) ($this->batch)()->current_value)->toBe(100_333)
        ->and(StockLedger::verify())->toBe([]);
});

/**
 * The live clean-up: goods that left BEFORE batches carried their exact value
 * went at quantity x the rounded unit cost, and left 3 paisa on an empty
 * batch. Written here the way the old code wrote them.
 */
it('sweeps value left on an empty batch to head 5180, dry run first', function () {
    $batch = ($this->batch)();

    foreach (['400' => '2026-10-12', '600' => '2026-10-13'] as $kg => $on) {
        $movement = StockMovement::create([
            'product_id' => $batch->product_id, 'warehouse_id' => $batch->warehouse_id, 'batch_id' => $batch->id,
            'direction' => MovementDirection::Out, 'reason' => MovementReason::Usage, 'quantity' => $kg,
            'unit_cost' => $batch->unit_cost,
            'value_minor' => Money::ofProduct($kg, (string) $batch->unit_cost)->minor,
            'performed_by' => $this->w->owner->id, 'occurred_at' => $on,
        ]);
        ($this->post)(collect([$movement]), $on, "Old-style sale of {$kg} kg");
    }

    $batch->refresh();
    expect((string) $batch->current_quantity)->toBe('0.0000')
        ->and((int) $batch->current_value)->toBe(3)
        ->and(($this->stockLedger)())->toBe(3)
        ->and(implode(' ', StockLedger::verify()))->toContain('holds nothing but is worth')
        ->and(implode(' ', SubledgerReconciliation::reconcileCurrentBusiness()['warnings']))->toContain('empty stock batch(es) still carry 0.03');

    // Dry run: lists it, changes nothing.
    $this->artisan('inventory:sweep-batch-dust')
        ->expectsOutputToContain('holds nothing but is worth 0.03')
        ->assertSuccessful();
    $this->w->enter($this->w->business);
    expect((int) $batch->fresh()->current_value)->toBe(3)
        ->and(StockCostDifference::count())->toBe(0);

    $this->artisan('inventory:sweep-batch-dust --apply')->assertFailed();

    $this->artisan('inventory:sweep-batch-dust', ['--apply' => true, '--by' => (string) $this->w->owner->id])
        ->expectsOutputToContain('cleared 0.03')
        ->assertSuccessful();
    $this->w->enter($this->w->business);

    $difference = StockCostDifference::query()->sole();
    $head = Account::bySlug('stock_cost_correction_consumed');

    expect((int) $batch->fresh()->current_value)->toBe(0)
        ->and((string) $difference->amount)->toBe('-0.03')
        ->and($difference->journal_id)->not->toBeNull()
        ->and((int) DB::table('journal_lines')->where('journal_id', $difference->journal_id)->where('account_id', $head->id)->sum('amount_minor'))->toBe(3)
        ->and(($this->stockLedger)())->toBe(0);

    ($this->reconciles)();

    // Run again: nothing left to sweep.
    $this->artisan('inventory:sweep-batch-dust')->expectsOutputToContain('No empty batch carries any value.')->assertSuccessful();
});
