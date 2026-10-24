<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceiptWorld;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): a goods receipt whose goods were
 * partly sold (the Tk 10,000 rule, FINAL-PLAN 4.3), and a receipt with a
 * supplier discount on it (Reverse greyed with what to undo first; a date
 * Correct carries the discount's link along).
 */
beforeEach(function () {
    $this->r = ReceiptWorld::make('2026-10-19 10:00:00');
    $this->policy = app(GoodsReceiptActionPolicy::class);

    // A sale as the books see it: FIFO out, and its cost Dr COGS / Cr Stock.
    $this->sell = function (string $quantity, string $on) {
        $r = $this->r;
        $movements = app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: $quantity, performedBy: $r->w->owner, occurredAt: $on);
        $value = StockValue::sum($movements)->absolute();

        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($on)->startOfDay(),
            lines: [JournalLineDraft::debit(Account::bySlug('cost_of_goods_sold')->id, $value), JournalLineDraft::credit(Account::bySlug('stock_on_hand')->id, $value)],
            memo: "Test sale of {$quantity} salt",
            source: $movements->first(),
        ), $r->w->owner);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a receipt part of which was sold: what is left goes back out, the sold part\'s cost posts today', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '25', '20.00']], '2026-10-15'));
    ($this->sell)('5', '2026-10-16');

    $availability = $this->policy->availability($receipt->fresh(), $r->w->manager, DocumentAction::Reverse);
    expect($availability->allowed)->toBeTrue();

    $preview = $r->preview($r->input($receipt, DocumentAction::Reverse), $r->w->manager);
    expect(collect($preview->effects)->pluck('sentence')->implode(' '))->toContain('were already sold or used')->toContain('Tk 100');

    $result = $r->reverse($receipt, $r->w->manager);
    $batch = StockBatch::find($receipt->lines->first()->stockMovement->batch_id);
    $difference = StockCostDifference::query()->where('source_id', $receipt->id)->sole();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and((string) $batch->current_quantity)->toBe('0.0000')
        ->and($difference->money()->minor)->toBe(10000)
        ->and((string) $difference->quantity_used)->toBe('5.0000')
        ->and($difference->posted_on->toDateString())->toBe('2026-10-19')
        ->and(Journal::find($difference->journal_id)->journal_date->toDateString())->toBe('2026-10-19')
        ->and((int) DB::table('journal_lines')->where('account_id', Account::bySlug('stock_cost_correction_consumed')->id)->sum('amount_minor'))->toBe(-10000)
        ->and($r->breaks())->toBe([]);
});

it('above Tk 10,000 of sold goods only the Owner may reverse it', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '900', '20.00']], '2026-10-15'));
    ($this->sell)('600', '2026-10-16');

    $blockers = collect($this->policy->availability($receipt->fresh(), $r->w->manager, DocumentAction::Reverse)->blockers);
    expect($blockers->pluck('code')->all())->toContain('sold_stock_owner')
        ->and($blockers->firstWhere('code', 'sold_stock_owner')->message)->toContain('Tk 12,000')->toContain('only the Owner');
    expect(fn () => $r->reverse($receipt, $r->w->manager))->toThrow(CorrectionBlockedException::class);

    $r->reverse($receipt, $r->w->owner);

    expect(StockCostDifference::query()->where('source_id', $receipt->id)->sole()->money()->minor)->toBe(1_200_000)
        ->and($r->breaks())->toBe([]);
});

it('corrects the quantity of a receipt part of which was sold, in the same batch, never below what was sold', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00']], '2026-10-15');
    $receipt = $r->receiptOf($order);
    ($this->sell)('5', '2026-10-16');

    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '4']])), $r->w->manager))
        ->toThrow(ValidationException::class, 'already sold or used');
    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20', 'different_lot' => true]])), $r->w->manager))
        ->toThrow(ValidationException::class, 'lot cannot change');

    $batchId = $receipt->lines->first()->stockMovement->batch_id;
    $result = $r->correct($receipt, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20']]), $r->w->manager);
    $successor = GoodsReceipt::find($result->link->successor_id);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and((int) $successor->lines->first()->stockMovement->batch_id)->toBe($batchId)
        ->and((string) StockBatch::find($batchId)->current_quantity)->toBe('15.0000')
        ->and((string) $order->lines->first()->fresh()->quantity_received)->toBe('20.0000')
        ->and(StockCostDifference::count())->toBe(0)
        ->and($r->breaks())->toBe([]);
});

it('greys Reverse when a supplier discount sits on the goods, and a date Correct carries the discount along', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00']], '2026-10-10');
    $r->d->bill($order, ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-D1'], 'INV-D1');
    $receipt = $r->receiptOf($order);
    $discount = SupplierDiscount::sole();

    $blocker = collect($this->policy->availability($receipt->fresh(), $r->w->owner, DocumentAction::Reverse)->blockers)->firstWhere('code', 'discount_on_receipt');
    expect($blocker->message)->toContain('reverse that discount first, then bill INV-D1, then this receipt');

    // Correct is not refused up front: a DATE correction keeps the batches.
    expect($this->policy->availability($receipt->fresh(), $r->w->owner, DocumentAction::Correct)->allowed)->toBeTrue();
    expect(fn () => $r->preview($r->input($receipt, DocumentAction::Correct, $r->form($receipt, [], [$r->d->salt->id => ['quantity' => '20']])), $r->w->owner))
        ->toThrow(ValidationException::class, 'reverse that discount first');

    $result = $r->correct($receipt, $r->form($receipt, ['received_on' => '2026-10-09']), $r->w->owner);
    $successor = GoodsReceipt::find($result->link->successor_id);

    expect($discount->allocations()->value('goods_receipt_line_id'))->toBe($successor->lines->first()->id)
        ->and($r->breaks())->toBe([]);
});

it('corrects a receipt in a closed month as the Owner only, and refuses the manager with a request route', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '25', '20.00']], '2026-09-20'));
    ($this->sell)('5', '2026-09-22');
    $r->w->closeThrough('2026-09-30');

    expect($this->policy->availability($receipt->fresh(), $r->w->manager, DocumentAction::Reverse)->isRequestable())->toBeTrue();

    $r->reverse($receipt, $r->w->owner);

    $difference = StockCostDifference::query()->where('source_id', $receipt->id)->sole();
    expect($receipt->fresh()->isReversed())->toBeTrue()
        ->and($difference->posted_on->toDateString())->toBe('2026-10-19')
        ->and(ClosedPeriodException::query()->withoutGlobalScopes()->sole()->status)->toBe(ClosedPeriodException::USED)
        ->and($r->breaks())->toBe([]);
});
