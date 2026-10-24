<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Carbon;
use Tests\Support\StockWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a received stock transfer is
 * reversed through the corrections engine, never deleted.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    $this->s->opening([[$this->s->ghee, '20', '900']]);
    $this->arrived = function (string $quantity, string $sent = '2026-10-10', string $arrived = '2026-10-11'): StockTransfer {
        $transfer = app(DispatchStockTransfer::class)->handle(
            product: $this->s->ghee, source: $this->s->savar, target: $this->s->shop, quantity: $quantity,
            reason: $this->s->reason('Warehouse rebalancing', 'inventory.stock_transfer'), performedBy: $this->w->manager, occurredAt: $sent,
        );
        app(ReceiveStockTransfer::class)->handle($transfer, $this->w->manager, $arrived);

        return $transfer->fresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a received transfer: the goods leave the shop and go back onto the batch they left', function () {
    $transfer = ($this->arrived)('5');
    $sourceBatch = StockMovement::where('transfer_group_id', $transfer->transfer_group_id)->where('direction', 'out')->sole()->batch_id;
    expect($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('5.0000');

    $input = $this->s->input(StockTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, reason: 'Sent to the wrong shop');
    $preview = $this->s->preview($input, $this->w->manager);
    expect($preview->blockers)->toBe([])
        ->and($preview->dates->reversalDate->toDateString())->toBe('2026-10-11')
        ->and($preview->effects[0]->sentence)->toContain('Dhanmondi shop back to Savar');

    $service = app(CorrectionService::class);
    $result = $service->submit($input, $this->w->manager, $preview->previewToken);
    expect($result->outcome)->toBe(CorrectionOutcome::Executed);

    $transfer->refresh();
    expect($transfer->isReversed())->toBeTrue()
        ->and($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->reversal_reason)->toBe('Sent to the wrong shop')
        ->and($transfer->reversed_on->toDateString())->toBe('2026-10-11')
        ->and($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('0.0000')
        ->and($this->s->onHand($this->s->ghee))->toBe('20.0000')
        ->and(StockMovement::where('batch_id', $sourceBatch)->where('direction', 'in')->whereNotNull('reverses_movement_id')->sum('quantity'))->toEqual(5)
        ->and(CorrectionLink::count())->toBe(1);
    $this->s->assertReconciles();

    // The same click again changes nothing; a new attempt is refused.
    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and($this->s->onHand($this->s->ghee))->toBe('20.0000')
        ->and(CorrectionLink::count())->toBe(1);
    expect(app(StockTransferActionPolicy::class)->availability($transfer->fresh(), $this->w->manager, DocumentAction::Reverse)->allowed)->toBeFalse();
});

it('refuses while any of the goods were used at the destination', function () {
    $transfer = ($this->arrived)('5');
    $this->s->sell($this->s->ghee, '1', '2026-10-12', $this->s->shop);

    $availability = app(StockTransferActionPolicy::class)->availability($transfer->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->message)->toContain('already used at Dhanmondi shop');

    expect(fn () => $this->s->submit($this->s->input(StockTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse), $this->w->manager))
        ->toThrow(Exception::class);
    expect($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('4.0000');
});

it('offers Cancel on the road and Reverse once arrived, to the stock keeper only', function () {
    $policy = app(StockTransferActionPolicy::class);
    $transfer = ($this->arrived)('2');

    expect(collect($policy->actionsFor($transfer, $this->w->manager)->actions)->map(fn ($a) => $a->action->value)->all())->toBe(['reverse'])
        ->and($policy->actionsFor($transfer, $this->s->staff)->actions)->toBe([]);

    expect(fn () => $this->s->preview($this->s->input(StockTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse), $this->s->staff))
        ->toThrow(Exception::class);
});

it('a closed month is the Owner\'s; the reason is required', function () {
    $transfer = ($this->arrived)('2', '2026-09-20', '2026-09-21');
    $this->w->closeThrough('2026-09-30');
    $policy = app(StockTransferActionPolicy::class);

    expect($policy->availability($transfer, $this->w->owner, DocumentAction::Reverse)->allowed)->toBeTrue()
        ->and($policy->availability($transfer, $this->w->manager, DocumentAction::Reverse)->allowed)->toBeFalse();

    $this->actingAs($this->w->owner)->postJson("/records/inventory.stock_transfer/{$transfer->id}/reverse/preview", ['reason_text' => 'x'])
        ->assertStatus(422);

    // The Owner goes ahead in the closed month: the goods are back, no journal.
    $result = $this->s->submit($this->s->input(StockTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, reason: 'Counted at the wrong shop'), $this->w->owner);
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($transfer->fresh()->isReversed())->toBeTrue()
        ->and($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('0.0000');
    $this->s->assertReconciles();
});

it('shows the Reverse button on the transfers screen', function () {
    $transfer = ($this->arrived)('2');

    $this->actingAs($this->w->manager)->get(route('inventory.stock.transfers.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/stock/transfers')
            ->where('transfers.data.0.id', $transfer->id)
            ->where('transfers.data.0.actions.0.action', 'reverse')
            ->has('reversalReasons'));
});
