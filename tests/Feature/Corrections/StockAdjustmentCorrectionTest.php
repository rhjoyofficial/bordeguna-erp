<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Corrections\InventoryAdjustmentActionPolicy;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\StockWorld;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): stock adjustments (FINAL-PLAN 3.3).
 * Waiting: Edit and Withdraw by the raiser. Approved: Reverse into the SAME
 * batches on the adjustment's own date, by the stock approver.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    $this->s->noSelfApproval();
    $this->opening = $this->s->opening([[$this->s->honey, '40', '800', 'H-01'], [$this->s->salt, '100', '60']]);
    $this->approve = fn (InventoryAdjustment $a) => app(ApproveInventoryAdjustment::class)->handle($a->fresh(), $this->w->manager, 'Seen the damaged jars');
});

afterEach(fn () => Carbon::setTestNow());

function reverseAdjustment(StockWorld $s, InventoryAdjustment $a, $by, ?string $key = null)
{
    return $s->submit($s->input(InventoryAdjustmentActionPolicy::TYPE, $a, DocumentAction::Reverse, key: $key), $by);
}

it('reverses an approved write-off back into the same batch on its own date', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '4', $this->s->staff);
    ($this->approve)($damage);
    $batch = $this->opening->lines->first()->stockMovement->batch;

    expect($this->s->onHand($this->s->honey))->toBe('36.0000')
        ->and($this->s->balance('stock_on_hand'))->toBe(3200000 + 600000 - 320000);

    $result = reverseAdjustment($this->s, $damage, $this->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($this->s->onHand($this->s->honey))->toBe('40.0000')
        ->and($batch->fresh()->current_quantity)->toBe('40.0000')
        ->and($this->s->balance('stock_on_hand'))->toBe(3800000);

    $damage->refresh();
    expect($damage->isReversed())->toBeTrue()
        ->and($damage->reversed_on->toDateString())->toBe('2026-10-05')
        ->and(Journal::find($damage->reversal_journal_id)->journal_date->toDateString())->toBe('2026-10-05');

    // Every unit came back onto the batch it left, naming the movement it undoes.
    $back = StockMovement::where('adjustment_id', $damage->id)->whereNotNull('reverses_movement_id')->get();
    expect($back)->toHaveCount(1)
        ->and((int) $back->first()->batch_id)->toBe($batch->id)
        ->and($back->first()->occurred_at->toDateString())->toBe('2026-10-05');

    $this->s->assertReconciles();
});

it('reverses found stock by taking it back out of the batch it made', function () {
    $found = $this->s->adjust('Physical count correction', AdjustmentDirection::Increase, $this->s->salt, '5', $this->w->owner, '60');
    ($this->approve)($found);

    expect($this->s->onHand($this->s->salt))->toBe('105.0000');

    reverseAdjustment($this->s, $found, $this->w->owner);

    expect($this->s->onHand($this->s->salt))->toBe('100.0000')
        ->and($found->fresh()->isReversed())->toBeTrue();
    $this->s->assertReconciles();
});

it('refuses to reverse found stock once its batch has been used, and says what to do', function () {
    $found = $this->s->adjust('Physical count correction', AdjustmentDirection::Increase, $this->s->ghee, '5', $this->w->owner, '900');
    ($this->approve)($found);
    $this->s->sell($this->s->ghee, '2', '2026-10-06');

    $availability = app(InventoryAdjustmentActionPolicy::class)->availability($found->fresh(), $this->w->manager, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('batch_used')
        ->and(collect($availability->blockers)->first(fn ($b) => $b->code === 'batch_used')->message)->toContain('Record the real count as damage or loss');

    expect(fn () => reverseAdjustment($this->s, $found, $this->w->manager))->toThrow(CorrectionBlockedException::class);
});

it('reverses once only, and a repeated click returns the same result', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '2', $this->s->staff);
    ($this->approve)($damage);
    $key = (string) Str::uuid();

    $first = reverseAdjustment($this->s, $damage, $this->w->manager, $key);
    $again = app(CorrectionService::class)->submit($this->s->input(InventoryAdjustmentActionPolicy::TYPE, $damage, DocumentAction::Reverse, key: $key), $this->w->manager, null);

    expect($again->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and($again->link->id)->toBe($first->link->id)
        ->and(StockMovement::where('adjustment_id', $damage->id)->whereNotNull('reverses_movement_id')->count())->toBe(1);

    expect(fn () => reverseAdjustment($this->s, $damage->fresh(), $this->w->manager))->toThrow(CorrectionBlockedException::class, 'already reversed');
});

it('gives Reverse only to the stock approver', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '2', $this->s->staff);
    ($this->approve)($damage);
    $policy = app(InventoryAdjustmentActionPolicy::class);

    expect($policy->actionsFor($damage->fresh(), $this->s->staff)->actions)->toBe([])
        ->and($policy->actionsFor($damage->fresh(), $this->w->manager)->get(DocumentAction::Reverse)->allowed)->toBeTrue();

    expect(fn () => $this->s->preview($this->s->input(InventoryAdjustmentActionPolicy::TYPE, $damage, DocumentAction::Reverse), $this->s->staff))
        ->toThrow(AuthorizationException::class);
});

it('in a closed month only the Owner reverses, as a one-document exception', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '2', $this->s->staff, on: '2026-09-20');
    ($this->approve)($damage);
    $this->w->closeThrough('2026-09-30');

    // The Manager may only ask the Owner.
    $managerSees = app(InventoryAdjustmentActionPolicy::class)->availability($damage->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($managerSees->isRequestable())->toBeTrue();

    $requested = reverseAdjustment($this->s, $damage, $this->w->manager);
    expect($requested->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($damage->fresh()->isReversed())->toBeFalse()
        ->and($this->s->onHand($this->s->honey))->toBe('38.0000');

    $requested->request->forceFill(['status' => 'withdrawn', 'pending_key' => null])->save();

    // The Owner reverses it on its own September date; September stays closed.
    reverseAdjustment($this->s, $damage, $this->w->owner);

    $reversal = Journal::find($damage->fresh()->reversal_journal_id);
    expect($reversal->journal_date->toDateString())->toBe('2026-09-20')
        ->and($reversal->period_exception_id)->not->toBeNull()
        ->and($this->s->onHand($this->s->honey))->toBe('40.0000')
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse();
    $this->s->assertReconciles();
});

it('sends a reversal above Tk 20,000 to a second person', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '30', $this->s->staff);
    ($this->approve)($damage);

    $result = reverseAdjustment($this->s, $damage, $this->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and(CorrectionRequest::count())->toBe(1)
        ->and($damage->fresh()->isReversed())->toBeFalse();
});

it('lets the raiser edit a waiting correction, with a reason, and restarts its approval', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '4', $this->s->staff);

    $this->actingAs($this->s->staff)
        ->put(route('inventory.stock.adjustments.update', $damage), [
            'effective_date' => '2026-10-04', 'quantity' => '3', 'what_happened' => $damage->reason_id, 'note' => 'One jar was fine',
        ])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->s->staff)
        ->put(route('inventory.stock.adjustments.update', $damage), [
            'effective_date' => '2026-10-04', 'quantity' => '3', 'what_happened' => $damage->reason_id, 'note' => 'One jar was fine',
            'reason_text' => 'Counted one jar twice',
        ])
        ->assertSessionHasNoErrors();

    $damage->refresh();
    expect($damage->quantity)->toBe('3.0000')
        ->and($damage->effective_date->toDateString())->toBe('2026-10-04')
        ->and((float) $damage->estimated_value)->toBe(2400.0)
        ->and($damage->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($this->s->onHand($this->s->honey))->toBe('40.0000');

    // Somebody else cannot.
    $this->actingAs($this->w->manager)
        ->put(route('inventory.stock.adjustments.update', $damage), [
            'effective_date' => '2026-10-04', 'quantity' => '1', 'what_happened' => $damage->reason_id, 'reason_text' => 'Manager wants one',
        ])
        ->assertSessionHasErrors('stock');

    expect($damage->fresh()->quantity)->toBe('3.0000');
});

it('lets the raiser withdraw a waiting correction, then edit and send it again without a reason', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '4', $this->s->staff);

    $this->actingAs($this->s->staff)->post(route('inventory.stock.adjustments.withdraw', $damage), ['reason_text' => 'x'])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->s->staff)->post(route('inventory.stock.adjustments.withdraw', $damage), ['reason_text' => 'Wrong product picked'])
        ->assertSessionHasNoErrors();

    $damage->refresh();
    expect($damage->approvalRequest->status)->toBe(ApprovalStatus::Returned)
        ->and(app(InventoryAdjustmentActionPolicy::class)->stageOf($damage)->value)->toBe('draft');

    // A withdrawn one cannot be approved.
    expect(fn () => ($this->approve)($damage))->toThrow(RuntimeException::class);

    $this->actingAs($this->s->staff)
        ->put(route('inventory.stock.adjustments.update', $damage), ['effective_date' => '2026-10-05', 'quantity' => '2', 'what_happened' => $damage->reason_id])
        ->assertSessionHasNoErrors();

    expect($damage->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending);
});

it('records the date the stock was damaged, not the day it was typed', function () {
    $this->actingAs($this->w->owner)->post(route('inventory.stock.adjustments.store'), [
        'product_id' => $this->s->salt->id, 'warehouse_id' => $this->s->savar->id, 'direction' => 'decrease',
        'quantity' => '2', 'reason_id' => $this->s->reason('Damaged')->id, 'effective_date' => '2026-10-02',
    ])->assertSessionHasNoErrors();

    expect(InventoryAdjustment::latest('id')->first()->effective_date->toDateString())->toBe('2026-10-02');
});

it('lists the corrections with their buttons', function () {
    $damage = $this->s->adjust('Damaged', AdjustmentDirection::Decrease, $this->s->honey, '4', $this->s->staff);

    $this->actingAs($this->s->staff)->get(route('inventory.stock.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/stock/adjustments')
            ->where('adjustments.data.0.id', $damage->id)
            ->where('adjustments.data.0.status', 'waiting')
            ->where('adjustments.data.0.actions.0.action', 'edit')
            ->where('adjustments.data.0.actions.1.action', 'withdraw'));
});
