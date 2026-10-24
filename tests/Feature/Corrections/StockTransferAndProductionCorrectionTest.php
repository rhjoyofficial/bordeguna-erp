<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Corrections\ProductionRunActionPolicy;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Support\ProductionRunForm;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockWorld;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): stock transfers (Cancel on the road,
 * Receive part with the shortage recorded) and production runs (Edit and
 * Delete a draft, Reverse and Correct a confirmed run), FINAL-PLAN 3.3.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    $this->s->opening([[$this->s->salt, '100', '60'], [$this->s->ghee, '20', '900']]);
    $this->send = fn (string $quantity) => app(DispatchStockTransfer::class)->handle(
        product: $this->s->ghee, source: $this->s->savar, target: $this->s->shop, quantity: $quantity,
        reason: $this->s->reason('Warehouse rebalancing', 'inventory.stock_transfer'), performedBy: $this->w->manager, occurredAt: '2026-10-10',
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── transfers ──────────────────────────────────────────────────────────────

it('cancels a transfer on the road: the stock is back on the batch it left', function () {
    $transfer = ($this->send)('5');
    expect($this->s->onHand($this->s->ghee))->toBe('15.0000');

    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.cancel', $transfer), ['reason_text' => 'x'])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.cancel', $transfer), ['reason_text' => 'Van broke down, not sent'])
        ->assertSessionHasNoErrors();

    $transfer->refresh();
    expect($transfer->status)->toBe(TransferStatus::Cancelled)
        ->and($transfer->cancel_reason)->toBe('Van broke down, not sent')
        ->and($this->s->onHand($this->s->ghee))->toBe('20.0000')
        ->and($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('0.0000');
    $this->s->assertReconciles();

    // Cancelled once; neither cancelled again nor received.
    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.cancel', $transfer), ['reason_text' => 'Again, by mistake'])
        ->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.receive', $transfer))->assertSessionHas('error');
});

it('receives a transfer short: what is missing becomes a loss with its reason, never vanishes', function () {
    $transfer = ($this->send)('5');

    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.receive-part', $transfer), [
        'received_quantity' => '3', 'loss_reason_id' => $this->s->reason('Broken/Damaged')->id, 'reason_text' => 'Two jars broke on the way',
    ])->assertSessionHasNoErrors();

    $transfer->refresh();
    $shortage = InventoryAdjustment::where('source_type', $transfer->getMorphClass())->where('source_id', $transfer->id)->sole();

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->received_quantity)->toBe('3.0000')
        ->and($transfer->shortage_quantity)->toBe('2.0000')
        ->and($shortage->quantity)->toBe('2.0000')
        ->and($shortage->note)->toContain('Two jars broke on the way');

    // The approval limit applies as to any loss; approved, the shop holds 3.
    app(ApproveInventoryAdjustment::class)->handle($shortage, $this->w->owner, 'Seen the broken jars');
    expect($this->s->onHand($this->s->ghee, $this->s->shop))->toBe('3.0000');
    $this->s->assertReconciles();
});

it('refuses a short receipt that is not short, and keeps the transfer for Store', function () {
    $transfer = ($this->send)('5');

    $this->actingAs($this->w->manager)->post(route('inventory.stock.transfers.receive-part', $transfer), [
        'received_quantity' => '5', 'loss_reason_id' => $this->s->reason('Broken/Damaged')->id, 'reason_text' => 'All of it came',
    ])->assertSessionHasErrors('received_quantity');

    $this->actingAs($this->s->staff)->post(route('inventory.stock.transfers.cancel', $transfer), ['reason_text' => 'Not mine to cancel'])
        ->assertForbidden();

    expect($transfer->fresh()->isInTransit())->toBeTrue();
    expect(app(StockTransferActionPolicy::class)->actionsFor($transfer, $this->w->manager)->get(DocumentAction::Cancel)->allowed)->toBeTrue();
});

// ─── production runs ────────────────────────────────────────────────────────

function saltJar(StockWorld $s): Product
{
    return Product::create(['business_id' => $s->w->business->id, 'unit_of_measure_id' => $s->ghee->unit_of_measure_id, 'sku' => 'SALT-JAR', 'name' => 'Pink Salt jar 500g', 'type' => ProductType::FinishedGood]);
}

function runForm(StockWorld $s, Product $jar, string $made = '20', string $salt = '10', string $on = '2026-10-08'): array
{
    return [
        'warehouse_id' => $s->savar->id, 'output_product_id' => $jar->id, 'output_quantity' => $made,
        'occurred_at' => $on, 'expense_waste' => false, 'note' => null,
        'lines' => [['product_id' => $s->salt->id, 'quantity' => $salt, 'waste' => '0', 'unit_id' => null]],
        'costs' => [],
    ];
}

function recordRun(StockWorld $s, array $form, bool $confirm = true): ProductionRun
{
    $action = app(RecordProductionRun::class);
    $run = $action->draft(...ProductionRunForm::arguments($form), createdBy: $s->w->manager);

    return $confirm ? $action->confirm($run, $s->w->manager) : $run;
}

it('edits a draft run in place, and deletes a draft only with a reason', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar), confirm: false);

    $this->actingAs($this->w->manager)->put(route('inventory.production-runs.update', $run), runForm($this->s, $jar, '18', '9'))
        ->assertRedirect(route('inventory.production-runs.show', $run));

    $run->refresh();
    expect($run->output_quantity)->toBe('18.0000')
        ->and($run->lines()->sole()->quantity_consumed)->toBe('9.0000')
        ->and($run->isDraft())->toBeTrue()
        ->and($this->s->onHand($this->s->salt))->toBe('100.0000');

    $this->actingAs($this->w->manager)->delete(route('inventory.production-runs.destroy', $run))->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->manager)->delete(route('inventory.production-runs.destroy', $run), ['reason_text' => 'Entered twice by mistake'])
        ->assertRedirect(route('inventory.production-runs.index'));

    expect(ProductionRun::count())->toBe(0);
});

it('does not edit a confirmed run', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar));

    $this->actingAs($this->w->manager)->put(route('inventory.production-runs.update', $run), runForm($this->s, $jar, '18', '9'))
        ->assertSessionHasErrors('run');

    expect($run->fresh()->output_quantity)->toBe('20.0000');
});

it('corrects a confirmed run: the wrong one reversed on its own date, the right one made', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar));

    expect($this->s->onHand($this->s->salt))->toBe('90.0000')
        ->and($this->s->onHand($jar))->toBe('20.0000');

    $result = $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Correct, runForm($this->s, $jar, '24', '12'), 'Used 12 kg, made 24 jars'), $this->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed);

    $run->refresh();
    $successor = ProductionRun::whereKey($result->link->successor_id)->sole();

    expect($run->status)->toBe(ProductionRunStatus::Reversed)
        ->and($run->reversed_on->toDateString())->toBe('2026-10-08')
        ->and($run->reversal_reason)->toContain('Used 12 kg')
        ->and($successor->isConfirmed())->toBeTrue()
        ->and($successor->run_number)->not->toBe($run->run_number)
        ->and($this->s->onHand($this->s->salt))->toBe('88.0000')
        ->and($this->s->onHand($jar))->toBe('24.0000')
        ->and(CorrectionLink::where('original_id', $run->id)->sole()->successor_number)->toBe($successor->run_number);

    $this->s->assertReconciles();
});

it('reverses a confirmed run whose output was partly sold: the rest comes out, the cost difference posts today', function () {
    // ⭐ 24 Oct 2026: refused until the wave 1 gaps were closed.
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar));
    $sold = recordRun($this->s, runForm($this->s, $jar, '10', '5', '2026-10-09'));
    $this->s->sell($jar, '25', '2026-10-12');

    $availability = app(ProductionRunActionPolicy::class)->availability($sold->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($availability->allowed)->toBeTrue();

    $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $sold, DocumentAction::Reverse, reason: 'Second run never happened'), $this->w->manager);

    $difference = StockCostDifference::query()->where('source_id', $sold->id)->sole();
    expect($sold->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and($this->s->onHand($jar))->toBe('0.0000')
        ->and($this->s->onHand($this->s->salt))->toBe('90.0000')
        ->and($difference->money()->minor)->toBe(15000)
        ->and((string) $difference->quantity_used)->toBe('5.0000');
    $this->s->assertReconciles();

    // The old bare button is retired: no reason, no own date.
    $this->actingAs($this->w->manager)->post(route('inventory.production-runs.reverse', $run))->assertSessionHasErrors('run');
});

it('reverses an untouched run on its own date', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar));

    $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Reverse, reason: 'Run never happened'), $this->w->manager);

    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and($this->s->onHand($this->s->salt))->toBe('100.0000')
        ->and($this->s->onHand($jar))->toBe('0.0000');
    $this->s->assertReconciles();
});

it('refuses a correction that changes nothing; a closed month is for the Owner', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar, on: '2026-09-20'));

    expect(fn () => $this->s->preview($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Correct, runForm($this->s, $jar, on: '2026-09-20')), $this->w->manager))
        ->toThrow(ValidationException::class, 'Nothing was changed');

    // ⭐ 24 Oct 2026: no longer refused for the Owner; the manager may ask the Owner.
    $this->w->closeThrough('2026-09-30');
    $owner = app(ProductionRunActionPolicy::class)->availability($run->fresh(), $this->w->owner, DocumentAction::Reverse);
    $manager = app(ProductionRunActionPolicy::class)->availability($run->fresh(), $this->w->manager, DocumentAction::Reverse);

    expect($owner->allowed)->toBeTrue()
        ->and($manager->allowed)->toBeFalse()
        ->and($manager->isRequestable())->toBeTrue();
});

it('gives the run buttons to the production manager only', function () {
    $jar = saltJar($this->s);
    $run = recordRun($this->s, runForm($this->s, $jar));
    $policy = app(ProductionRunActionPolicy::class);

    expect($policy->actionsFor($run, $this->s->staff)->actions)->toBe([])
        ->and(collect($policy->actionsFor($run, $this->w->manager)->actions)->map(fn ($a) => $a->action->value)->all())->toBe(['correct', 'reverse']);
});

it('opens the screens: transfers with their buttons, a draft to edit, a run to correct', function () {
    $transfer = ($this->send)('2');
    $jar = saltJar($this->s);
    $draft = recordRun($this->s, runForm($this->s, $jar), confirm: false);
    $run = recordRun($this->s, runForm($this->s, $jar, '10', '5'));

    $this->actingAs($this->w->manager)->get(route('inventory.stock.transfers.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/stock/transfers')
            ->where('transfers.data.0.id', $transfer->id)
            ->where('transfers.data.0.actions.0.action', 'cancel'));

    $this->actingAs($this->w->manager)->get(route('inventory.production-runs.edit', $draft))->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/production-runs/create')
            ->where('mode', 'edit')
            ->where('run.form.output_quantity', '20'));

    $this->actingAs($this->w->manager)->get(route('inventory.production-runs.correct', $run))->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/production-runs/create')
            ->where('mode', 'correct')
            ->where('availability.allowed', true));

    $this->actingAs($this->w->manager)->get(route('inventory.production-runs.show', $run))->assertOk()
        ->assertInertia(fn ($page) => $page->where('actions.0.action', 'correct')->where('actions.1.action', 'reverse'));
});
