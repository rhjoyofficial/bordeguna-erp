<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Support\ReceiptBilling;
use App\Modules\Purchasing\Support\ReceiptJournals;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceiptWorld;

/**
 * ⭐ Goods receipt Correct (corrections wave 1, 19 Oct 2026).
 *
 * The real trigger: two hilsa receipts posted 18/10/2026 for goods that
 * arrived 04/08/2026 and 30/09/2026, already billed and paid; a sale of
 * 05/08 could not be delivered because the stock "did not exist" until 18/10.
 */
beforeEach(function () {
    $this->r = ReceiptWorld::make('2026-10-19 10:00:00');
    $this->stock = fn (string $on = '2026-10-18') => AccountMapping::resolve('inventory.stock_on_hand', Carbon::parse($on))->id;
});

afterEach(fn () => Carbon::setTestNow());

it('moves a billed and paid receipt from 18/10 to 04/08 and 30/09, keeps the batch, and the 05/08 sale can then be delivered', function () {
    $r = $this->r;
    $august = $r->received([[$r->d->salt, '40', '500.00']], '2026-10-18');
    $september = $r->received([[$r->d->sugar, '20', '250.00']], '2026-10-18');

    foreach ([$august, $september] as $i => $order) {
        $receipt = $r->receiptOf($order);
        $bill = app(RecordSupplierBill::class)->handle(
            supplier: $r->d->supplier,
            billNumber: 'HILSA-'.$i,
            billDate: '2026-10-18',
            lines: $order->lines->map(fn ($line) => ['line' => $line, 'quantity' => (string) $line->quantity_received, 'unitCost' => (string) $line->unit_cost, 'receiptLine' => $receipt->lines->firstWhere('purchase_order_line_id', $line->id)])->all(),
            createdBy: $r->w->owner,
            purchaseOrder: $order,
        );
        $r->d->pay($bill, (string) $bill->total);
    }

    // Before: the 05/08 sale cannot take the stock that "arrived" on 18/10.
    expect(fn () => app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: '5', performedBy: $r->w->owner, occurredAt: '2026-08-05'))
        ->toThrow(Exception::class);

    $originalA = $r->receiptOf($august);
    $batchA = $originalA->lines->first()->stockMovement->batch_id;
    $billLine = SupplierBillLine::query()->where('goods_receipt_line_id', $originalA->lines->first()->id)->sole();

    $result = $r->correct($originalA, $r->form($originalA, ['received_on' => '2026-08-04']), $r->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-18');

    /** @var GoodsReceipt $successor */
    $successor = $result->successor->fresh('lines.stockMovement');
    $originalA->refresh();

    expect($originalA->isReversed())->toBeTrue()
        ->and($successor->received_at->toDateString())->toBe('2026-08-04')
        // the SAME batch, now received on the true day
        ->and($successor->lines->first()->stockMovement->batch_id)->toBe($batchA)
        ->and(StockBatch::find($batchA)->received_at->toDateString())->toBe('2026-08-04')
        ->and((string) StockBatch::find($batchA)->current_quantity)->toBe('40.0000')
        // the journal moved with it
        ->and(ReceiptJournals::live($successor)->journal_date->toDateString())->toBe('2026-08-04')
        // the bill follows the receipt; nothing on it changed
        ->and($billLine->fresh()->goods_receipt_line_id)->toBe($successor->lines->first()->id)
        ->and(CorrectionLink::sole()->successor_number)->toBe("Receipt #{$successor->id}");

    // Stock on Hand: Tk 20,000 on 04/08, none added on 18/10.
    expect($r->w->balance(Account::find(($this->stock)()), '2026-08-01', '2026-08-31'))->toBe(2_000_000)
        ->and($r->w->balance(Account::find(($this->stock)()), '2026-10-18', '2026-10-18'))->toBe(500_000);

    // The second receipt, to 30/09.
    $resultB = $r->correct($r->receiptOf($september), $r->form($r->receiptOf($september), ['received_on' => '2026-09-30']), $r->w->manager);
    expect($resultB->successor->received_at->toDateString())->toBe('2026-09-30');

    // Nothing billable twice; every ledger agrees with its documents.
    foreach ([$august, $september] as $order) {
        foreach ($order->lines as $line) {
            expect(array_sum(array_map('floatval', ReceiptBilling::billableByReceiptLine($line->fresh()))))->toBe(0.0);
        }
    }

    expect($r->breaks())->toBe([]);

    // After: the 05/08 sale is delivered from the re-dated batch.
    $moves = app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: '5', performedBy: $r->w->owner, occurredAt: '2026-08-05');
    expect($moves->first()->batch_id)->toBe($batchA);

    // The stock card keeps the wrong day and its correction.
    expect(StockMovement::query()->where('batch_id', $batchA)->where('reason', MovementReason::ReceiptCorrection->value)->count())->toBe(1);
});

it('refuses to move a receipt to a later day than a sale already taken from its batch', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '10', '20.00']], '2026-09-30');
    app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: '2', performedBy: $r->w->owner, occurredAt: '2026-10-05');
    $receipt = $r->receiptOf($order);

    $preview = fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, ['received_on' => '2026-10-10'])), $r->w->manager);

    expect($preview)->toThrow(ValidationException::class, 'was used on 5 Oct 2026, before 10 Oct 2026');

    // Earlier is always fine, even with part sold.
    $result = $r->correct($receipt, $r->form($receipt, ['received_on' => '2026-09-01']), $r->w->manager);
    expect($result->successor->received_at->toDateString())->toBe('2026-09-01')
        ->and((string) StockBatch::find($result->successor->lines->first()->stockMovement->batch_id)->current_quantity)->toBe('8.0000');
});

it('corrects a quantity on untouched, unbilled goods: back out, the order line given back, the right receipt in a new batch', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00']], '2026-10-15');
    $receipt = $r->receiptOf($order);
    $oldBatch = $receipt->lines->first()->stockMovement->batch_id;

    $result = $r->correct($receipt, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20']]), $r->w->manager);

    $line = $order->lines->first()->fresh();
    expect((string) $line->quantity_received)->toBe('20.0000')
        ->and($order->fresh()->status->value)->toBe('partially_received')
        ->and((string) StockBatch::find($oldBatch)->current_quantity)->toBe('0.0000')
        ->and($result->successor->lines->first()->stockMovement->batch_id)->not->toBe($oldBatch)
        ->and((string) $result->successor->lines->first()->quantity_received)->toBe('20.0000')
        ->and($r->breaks())->toBe([]);
});

it('refuses a quantity correction once the goods are billed, and points to the date-only path', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00']], '2026-10-15');
    $r->d->bill($order, null, 'INV-Q');
    $receipt = $r->receiptOf($order);

    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20']])), $r->w->manager))
        ->toThrow(ValidationException::class, 'correct only the date');
});

it('once part is sold, a quantity correction keeps the batch and never goes below what was sold', function () {
    // ⭐ 24 Oct 2026: refused until the wave 1 gaps were closed; now the same
    // batch is resized (more in GoodsReceiptSoldStockTest).
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00']], '2026-10-15');
    app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: '1', performedBy: $r->w->owner, occurredAt: '2026-10-16');
    $receipt = $r->receiptOf($order);

    $preview = $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20']])), $r->w->manager);
    expect($preview->blockers)->toBe([]);

    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '0.5']])), $r->w->manager))
        ->toThrow(ValidationException::class, 'already sold or used');
});

it('refuses a correction that changes nothing, and sends label-only changes to Fix batch details', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-15'));

    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt)), $r->w->manager))
        ->toThrow(ValidationException::class, 'Nothing was changed');

    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['batch_number' => 'LOT-9']])), $r->w->manager))
        ->toThrow(ValidationException::class, 'Use Fix batch details');
});

it('a corrected receipt cannot be corrected again, but its correction can', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-15'));
    $first = $r->correct($receipt, $r->form($receipt, ['received_on' => '2026-10-10']), $r->w->manager);

    $policy = app(GoodsReceiptActionPolicy::class);
    expect(collect($policy->availability($receipt->fresh(), $r->w->manager, DocumentAction::Correct)->blockers)->pluck('code')->all())->toContain('already_corrected');

    $second = $r->correct($first->successor->fresh(), $r->form($first->successor->fresh(), ['received_on' => '2026-10-08']), $r->w->manager);
    expect($second->successor->received_at->toDateString())->toBe('2026-10-08')
        ->and($r->breaks())->toBe([]);
});

it('a closed month: the manager is refused, the Owner corrects on the original day as a one-document exception', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-09-20'));
    $r->w->closeThrough('2026-09-30');

    $policy = app(GoodsReceiptActionPolicy::class);
    expect(collect($policy->availability($receipt, $r->w->manager, DocumentAction::Correct)->blockers)->pluck('code')->all())->toContain('period_closed_not_owner');

    $result = $r->correct($receipt, $r->form($receipt, ['received_on' => '2026-09-10']), $r->w->owner);

    expect($result->link->closed_period_exception)->toBeTrue()
        ->and(Journal::query()->whereNotNull('period_exception_id')->count())->toBe(2)
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse()
        ->and($result->successor->received_at->toDateString())->toBe('2026-09-10');
});

it('above Tk 20,000 a correction waits for a second person and posts nothing until then', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '100', '300.00']], '2026-10-15'));

    $result = $r->correct($receipt, $r->form($receipt, ['received_on' => '2026-10-01']), $r->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($receipt->fresh()->isReversed())->toBeFalse()
        ->and(CorrectionLink::count())->toBe(0);
});
