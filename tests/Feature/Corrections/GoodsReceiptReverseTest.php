<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ReceiptWorld;

/**
 * ⭐ Goods receipt Reverse (corrections wave 1, 19 Oct 2026): goods untouched,
 * nothing billed. The stock goes back out, the order line gets its quantity
 * back, the receipt journal is reversed on the receipt's own date, and the
 * receipt stays, marked Reversed.
 */
beforeEach(function () {
    $this->r = ReceiptWorld::make('2026-10-19 10:00:00');
    $this->policy = app(GoodsReceiptActionPolicy::class);
});

afterEach(fn () => Carbon::setTestNow());

it('reverses an untouched receipt: stock out, order line given back, journal reversed on its own date, ledgers agree', function () {
    $r = $this->r;
    $order = $r->received([[$r->d->salt, '25', '20.00'], [$r->d->sugar, '10', '10.00']], '2026-10-15');
    $receipt = $r->receiptOf($order);
    $batches = $receipt->lines->map(fn ($l) => $l->stockMovement->batch_id);

    $result = $r->reverse($receipt, $r->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($receipt->fresh()->isReversed())->toBeTrue()
        ->and($receipt->fresh()->reversal_journal_id)->toBe($result->reversalJournal->id);

    foreach ($batches as $batch) {
        expect((string) StockBatch::find($batch)->current_quantity)->toBe('0.0000');
    }

    foreach ($order->lines as $line) {
        expect((string) $line->fresh()->quantity_received)->toBe('0.0000');
    }

    expect($order->fresh()->status->value)->toBe('open')
        ->and($r->breaks())->toBe([]);

    // The order can receive again, with the same challan, as the goods arrive.
    $order = $order->fresh(['lines', 'approvalRequest', 'warehouse']);
    app(RecordGoodsReceipt::class)->handle($order, $order->lines->map(fn ($l) => ['line' => $l, 'quantity' => '1'])->all(), $r->w->owner);
    expect(GoodsReceipt::query()->live()->count())->toBe(1);
});

it('no longer refuses Reverse once goods are used (the Tk 10,000 rule), and still refuses once billed', function () {
    $r = $this->r;
    $used = $r->receiptOf($r->received([[$r->d->salt, '25', '20.00']], '2026-10-15'));
    app(RecordStockOut::class)->handle(product: $r->d->salt, warehouse: $r->d->warehouse, quantity: '1', performedBy: $r->w->owner, occurredAt: '2026-10-16');

    // ⭐ 24 Oct 2026: the used part follows the sold-stock cost rule now
    // (GoodsReceiptSoldStockTest); only unsafe cases refuse.
    $blockers = collect($this->policy->availability($used, $r->w->manager, DocumentAction::Reverse)->blockers);
    expect($blockers->pluck('code')->all())->not->toContain('stock_moved');

    $billedOrder = $r->received([[$r->d->sugar, '10', '10.00']], '2026-10-15');
    $r->d->bill($billedOrder, null, 'INV-R');
    $billed = $r->receiptOf($billedOrder);

    expect(collect($this->policy->availability($billed, $r->w->manager, DocumentAction::Reverse)->blockers)->pluck('code')->all())->toContain('billed');
    expect(fn () => $r->reverse($billed, $r->w->manager))->toThrow(CorrectionBlockedException::class);
    expect($billed->fresh()->isReversed())->toBeFalse();
});

it('requires a reason from the server, and refuses users without purchasing.correct', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-15'));
    $url = '/records/'.GoodsReceiptActionPolicy::TYPE."/{$receipt->id}/reverse";

    $this->actingAs($r->w->manager)
        ->withSession(['current_business_id' => $r->w->business->id])
        ->postJson($url, ['reason_text' => 'ok', 'idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('reason_text');

    foreach ([$r->staff, $r->w->financeB] as $user) {
        $this->actingAs($user)
            ->withSession(['current_business_id' => $r->w->business->id])
            ->postJson("{$url}/preview", ['reason_text' => 'Entered twice by mistake', 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(403);

        expect(collect($this->policy->actionsFor($receipt, $user)->actions)->pluck('action')->all())->not->toContain(DocumentAction::Reverse);
    }

    expect($receipt->fresh()->isReversed())->toBeFalse();
});

it('a double submit with the same key reverses once', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-10-15'));
    $input = $r->input($receipt, DocumentAction::Reverse);
    $service = app(CorrectionService::class);
    $token = $service->preview($input, $r->w->manager)->previewToken;

    $service->submit($input, $r->w->manager, $token);
    $again = $service->submit($input, $r->w->manager, $token);

    expect($again->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and(CorrectionLink::count())->toBe(1)
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1)
        ->and((string) StockBatch::query()->sole()->current_quantity)->toBe('0.0000');
});

it('a closed month blocks the manager (requestable to the Owner)', function () {
    $r = $this->r;
    $receipt = $r->receiptOf($r->received([[$r->d->salt, '5', '20.00']], '2026-09-20'));
    $r->w->closeThrough('2026-09-30');

    $availability = $this->policy->availability($receipt, $r->w->manager, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and($availability->isRequestable())->toBeTrue();
});
