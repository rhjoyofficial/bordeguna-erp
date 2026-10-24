<?php

use App\Modules\Assets\Actions\CountAssets;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenancePlan;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\ThingsWorld;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): the things register put right
 * (FINAL-PLAN 3.5). Delete a never-used thing (also in bulk), Dispose and Undo
 * disposal, Reverse a hand-over and Cancel a transfer on its way, Cancel an
 * open count, Reopen done maintenance. Every action: its permission, its
 * reason on the server, its blockers, and a second click that changes
 * nothing.
 */
beforeEach(function () {
    $this->t = ThingsWorld::make();
    $this->w = $this->t->w;
    $this->why = ['reason_text' => 'Entered by mistake'];
    $this->holdings = fn (AssetItem $item) => AssetHolding::query()->where('asset_item_id', $item->id)->orderBy('holder_key')->pluck('quantity', 'holder_key')->all();
});

afterEach(fn () => Carbon::setTestNow());

// ── Delete ──────────────────────────────────────────────────────────────

it('deletes a never-used thing to the bin with a reason, keeps its tag reserved, and the Owner restores it', function () {
    $laptop = $this->t->thing();

    $this->actingAs($this->t->keeper)->post(route('assets.items.delete'), ['ids' => [$laptop->id]])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->sales)->post(route('assets.items.delete'), ['ids' => [$laptop->id]] + $this->why)->assertForbidden();

    $this->actingAs($this->t->keeper)->post(route('assets.items.delete'), ['ids' => [$laptop->id]] + $this->why)->assertSessionHas('success');

    expect(AssetItem::query()->count())->toBe(0)
        ->and(AssetItem::onlyTrashed()->sole()->delete_reason)->toBe('Entered by mistake')
        ->and($this->t->thing('Second laptop')->tag)->toBe('BDG-IT-0002');

    $this->actingAs($this->t->keeper)->get(route('assets.items.show', $laptop->id))->assertNotFound();

    $entries = app(RecycleBin::class)->entries($this->w->owner);
    expect($entries->pluck('type')->all())->toBe(['asset_item'])
        ->and($entries->first()['label'])->toBe('Thing BDG-IT-0001 ThinkPad T470s');

    $this->actingAs($this->w->owner)->post("/settings/recycle-bin/asset_item/{$laptop->id}/restore", ['reason_text' => 'It is real after all'])->assertSessionHas('success');
    expect(AssetItem::query()->count())->toBe(2);
});

it('refuses to delete a thing that was used, and a bulk delete is all or nothing', function () {
    $used = $this->t->thing('Used laptop');
    $fresh = $this->t->thing('Fresh laptop');
    $this->t->move(AssetMovementKind::Issue, [['item' => $used]], ['employee_id' => $this->t->rakib->id]);

    $this->actingAs($this->t->custodian)->post(route('assets.items.delete'), ['ids' => [$fresh->id, $used->id]] + $this->why)
        ->assertSessionHasErrors(['reason_text' => 'Nothing was deleted. BDG-IT-0001 has been handed over, so it cannot be deleted. Reverse the hand-over first, or Dispose it if it left the company.']);

    expect(AssetItem::query()->count())->toBe(2);

    // Reversed, the hand-over never really happened: the thing is unused again.
    $movement = AssetMovement::query()->sole();
    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $movement), $this->why)->assertSessionHas('success');
    $this->actingAs($this->t->custodian)->post(route('assets.items.delete'), ['ids' => [$fresh->id, $used->id]] + $this->why)->assertSessionHas('success');

    expect(AssetItem::query()->count())->toBe(0)->and(AssetItem::onlyTrashed()->count())->toBe(2);

    // A binned thing moves nowhere and is not expected at a count.
    expect(fn () => app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $fresh]], ['employee_id' => $this->t->rakib->id], $this->t->custodian))
        ->toThrow(ValidationException::class, 'no longer exists');
    $count = app(CountAssets::class)->start(Business::find($this->w->business->id), ['office_id' => $this->t->office->id], $this->t->custodian);
    expect($count->lines)->toHaveCount(0);
});

// ── Dispose ─────────────────────────────────────────────────────────────

it('disposes of a thing held by an employee, closing custody, and undoes it within 30 days', function () {
    $laptop = $this->t->thing('Old laptop', 'IT', ['purchase_price' => '15000', 'acquired_on' => '2025-01-01']);
    $this->t->move(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->t->rakib->id]);
    $plan = AssetMaintenancePlan::create(['business_id' => $this->w->business->id, 'asset_item_id' => $laptop->id, 'title' => 'Clean fan', 'every_count' => 3, 'every_unit' => 'month', 'next_due_on' => '2026-12-01', 'remind_from' => '2026-11-28', 'remind_days' => 3, 'created_by' => $this->w->owner->id]);

    $form = ['disposed_on' => '2026-10-20', 'method' => 'sold'] + $this->why;

    $this->actingAs($this->t->keeper)->post(route('assets.items.dispose', $laptop), $form)->assertForbidden();
    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), ['method' => 'sold'] + $this->why)->assertSessionHasErrors('disposed_on');
    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), ['disposed_on' => '2026-10-19', 'method' => 'mistake'] + $this->why)->assertSessionHasErrors('method');
    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), ['disposed_on' => '2024-01-01', 'method' => 'sold'] + $this->why)->assertSessionHasErrors('disposed_on');

    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), $form)->assertSessionHas('success');
    $gone = $laptop->fresh();

    expect($gone->status)->toBe(AssetStatus::Disposed)
        ->and($gone->custodian_employee_id)->toBeNull()
        ->and($gone->disposal_method)->toBe('sold')
        ->and($gone->disposal_snapshot['last_holder'])->toBe('Rakib Test')
        ->and($plan->fresh()->is_active)->toBeFalse();

    // A second click is refused, not a second disposal.
    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), $form)->assertSessionHasErrors(['reason_text' => 'BDG-IT-0001 was already disposed.']);

    $this->actingAs($this->t->custodian)->post(route('assets.items.undo-disposal', $laptop), ['reason_text' => 'Sale fell through'])->assertSessionHas('success');
    $back = $laptop->fresh();

    expect($back->status)->toBe(AssetStatus::InUse)
        ->and($back->custodian_employee_id)->toBe($this->t->rakib->id)
        ->and($back->disposal_snapshot)->toBeNull()
        ->and($plan->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $laptop), $form)->assertSessionHas('success');
    Carbon::setTestNow('2026-11-25 10:00:00');
    $this->actingAs($this->t->custodian)->post(route('assets.items.undo-disposal', $laptop), ['reason_text' => 'Too late now'])
        ->assertSessionHasErrors(['reason_text' => 'BDG-IT-0001 was disposed more than 30 days ago, so the disposal stands.']);
});

it('leaves a thing above the approval limit to the Owner or a Manager, and refuses while it is on an open count', function () {
    $server = $this->t->thing('Server', 'IT', ['purchase_price' => '250000']);
    $form = ['disposed_on' => '2026-10-20', 'method' => 'scrapped'] + $this->why;

    $this->actingAs($this->t->custodian)->post(route('assets.items.dispose', $server), $form)
        ->assertSessionHasErrors(['reason_text' => 'BDG-IT-0001 cost more than the approval limit, so the Owner or a Manager disposes of it.']);

    $count = app(CountAssets::class)->start(Business::find($this->w->business->id), ['office_id' => $this->t->office->id], $this->t->custodian);
    $this->actingAs($this->w->manager)->post(route('assets.items.dispose', $server), $form)
        ->assertSessionHasErrors(['reason_text' => "BDG-IT-0001 is on the open count {$count->number}. Close or cancel that count first."]);

    $this->actingAs($this->t->custodian)->post(route('assets.counts.cancel', $count), ['reason_text' => 'Opened by mistake'])->assertSessionHas('success');
    $this->actingAs($this->w->manager)->post(route('assets.items.dispose', $server), $form)->assertSessionHas('success');

    expect($server->fresh()->status)->toBe(AssetStatus::Disposed);
});

// ── Hand-overs ──────────────────────────────────────────────────────────

it('reverses the latest hand-over with a linked mirror movement on its own day, once', function () {
    $laptop = $this->t->thing();
    $issue = $this->t->move(AssetMovementKind::Issue, [['item' => $laptop, 'condition' => 'fair']], ['employee_id' => $this->t->rakib->id]);

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $issue), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->sales)->post(route('assets.movements.reverse', $issue), $this->why)->assertForbidden();

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $issue), $this->why)->assertSessionHas('success');

    $reversal = AssetMovement::query()->where('reverses_movement_id', $issue->id)->sole();
    $laptop = $laptop->fresh();

    expect($reversal->kind)->toBe(AssetMovementKind::Return)
        ->and($reversal->moved_on->toDateString())->toBe('2026-10-20')
        ->and($reversal->from_employee_id)->toBe($this->t->rakib->id)
        ->and($reversal->to_office_id)->toBe($this->t->office->id)
        ->and($issue->fresh()->isReversed())->toBeTrue()
        ->and($issue->fresh()->reversal_reason)->toBe('Entered by mistake')
        ->and($laptop->custodian_employee_id)->toBeNull()
        ->and($laptop->status)->toBe(AssetStatus::InStore)
        ->and($laptop->condition->value)->toBe('good');

    // Double click: refused, and still one reversal.
    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $issue), $this->why)
        ->assertSessionHasErrors(['reason_text' => "{$issue->number} was already reversed."]);
    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $reversal), $this->why)
        ->assertSessionHasErrors(['reason_text' => "{$reversal->number} is itself a reversal. Record a new hand-over instead."]);

    expect(AssetMovement::query()->whereNotNull('reverses_movement_id')->count())->toBe(1);
});

it('refuses to reverse a hand-over the thing moved on from, or one older than 7 days', function () {
    $laptop = $this->t->thing();
    $issue = $this->t->move(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->t->rakib->id]);
    $pass = $this->t->move(AssetMovementKind::Reassign, [['item' => $laptop]], ['employee_id' => $this->t->nazmul->id]);

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $issue), $this->why)
        ->assertSessionHasErrors(['reason_text' => "BDG-IT-0001 moved again on {$pass->number}. Reverse that one first."]);

    Carbon::setTestNow('2026-10-28 10:00:00');
    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $pass), $this->why)
        ->assertSessionHasErrors(['reason_text' => "{$pass->number} is more than 7 days old. Record the opposite hand-over instead."]);
});

it('puts a counted group back with the holder its pieces left, and holdings agree with the movements', function () {
    $scissors = $this->t->group();
    $out = $this->t->move(AssetMovementKind::Issue, [['item' => $scissors, 'quantity' => 3]], ['employee_id' => $this->t->rakib->id]);

    expect(($this->holdings)($scissors))->toBe(['e'.$this->t->rakib->id => 3, 'o'.$this->t->office->id => 1]);

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $out), $this->why)->assertSessionHas('success');

    expect(($this->holdings)($scissors))->toBe(['o'.$this->t->office->id => 4]);
    $this->artisan('assets:rebuild-holdings')->expectsOutputToContain('Every counted group agrees')->assertSuccessful();
});

it('cancels a transfer on its way, which can then never be received, and reverses one that arrived', function () {
    $laptop = $this->t->thing();
    $scissors = $this->t->group();
    $road = $this->t->move(AssetMovementKind::Transfer, [['item' => $laptop], ['item' => $scissors, 'quantity' => 4]], ['warehouse_id' => $this->t->warehouse->id]);

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $road), ['reason_text' => 'Wrong warehouse'])->assertSessionHas('success');

    expect($road->fresh()->status)->toBe(AssetMovementStatus::Cancelled)
        ->and($laptop->fresh()->transit_movement_id)->toBeNull()
        ->and($laptop->fresh()->office_id)->toBe($this->t->office->id)
        ->and($scissors->fresh()->transit_movement_id)->toBeNull()
        ->and(($this->holdings)($scissors))->toBe(['o'.$this->t->office->id => 4]);

    expect(fn () => app(MoveAssets::class)->receive($road, $this->t->custodian))->toThrow(ValidationException::class, 'was cancelled before it arrived');

    $second = $this->t->move(AssetMovementKind::Transfer, [['item' => $laptop]], ['warehouse_id' => $this->t->warehouse->id]);
    app(MoveAssets::class)->receive($second, $this->t->custodian);
    expect($laptop->fresh()->warehouse_id)->toBe($this->t->warehouse->id);

    $this->actingAs($this->t->custodian)->post(route('assets.movements.reverse', $second), $this->why)->assertSessionHas('success');
    expect($laptop->fresh()->office_id)->toBe($this->t->office->id)->and($laptop->fresh()->warehouse_id)->toBeNull();
});

// ── Counts and maintenance ──────────────────────────────────────────────

it('cancels an open count without touching any thing, and frees the place; a closed count is refused', function () {
    $laptop = $this->t->thing();
    $business = Business::find($this->w->business->id);
    $count = app(CountAssets::class)->start($business, ['office_id' => $this->t->office->id], $this->t->custodian);
    app(CountAssets::class)->mark($count, $laptop, false, $this->t->custodian);

    $this->actingAs($this->t->custodian)->post(route('assets.counts.cancel', $count), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->t->custodian)->post(route('assets.counts.cancel', $count), ['reason_text' => 'Wrong place'])->assertSessionHas('success');

    expect($count->fresh()->status)->toBe('cancelled')
        ->and($count->fresh()->cancel_reason)->toBe('Wrong place')
        ->and($laptop->fresh()->status)->toBe(AssetStatus::InStore);

    $again = app(CountAssets::class)->start($business, ['office_id' => $this->t->office->id], $this->t->custodian);
    app(CountAssets::class)->mark($again, $laptop, true, $this->t->custodian);
    app(CountAssets::class)->close($again, $this->t->custodian);

    $this->actingAs($this->t->custodian)->post(route('assets.counts.cancel', $again), ['reason_text' => 'Too late'])
        ->assertSessionHasErrors(['reason_text' => "{$again->number} is closed. Settle its gaps instead."]);
    $this->actingAs($this->t->custodian)->post(route('assets.counts.cancel', $count), ['reason_text' => 'Again'])
        ->assertSessionHasErrors(['reason_text' => "{$count->number} was already cancelled."]);
    expect(AssetCount::query()->where('status', 'cancelled')->count())->toBe(1);
});

it('reopens done maintenance within 30 days, putting the thing back under repair', function () {
    $printer = $this->t->thing('Printer');
    $record = app(RecordMaintenance::class)->open($printer, ['kind' => 'repair', 'problem' => 'Paper jam', 'reported_on' => '2026-10-18', 'out_of_service' => true], $this->t->custodian);
    expect($printer->fresh()->status)->toBe(AssetStatus::UnderRepair);
    app(RecordMaintenance::class)->complete($record, ['done_on' => '2026-10-19', 'work_done' => 'Cleared the roller'], $this->t->custodian);
    expect($printer->fresh()->status)->toBe(AssetStatus::InStore);

    $this->actingAs($this->w->sales)->post(route('assets.maintenance.reopen', $record), $this->why)->assertForbidden();
    $this->actingAs($this->t->custodian)->post(route('assets.maintenance.reopen', $record), ['reason_text' => 'Done on the wrong printer'])->assertSessionHas('success');

    $open = $record->fresh();
    expect($open->state)->toBe(MaintenanceState::Open)
        ->and($open->done_on)->toBeNull()
        ->and($open->reopen_reason)->toBe('Done on the wrong printer')
        ->and($printer->fresh()->status)->toBe(AssetStatus::UnderRepair);

    $this->actingAs($this->t->custodian)->post(route('assets.maintenance.reopen', $record), $this->why)
        ->assertSessionHasErrors(['reason_text' => "{$record->number} is not done, so there is nothing to reopen."]);

    app(RecordMaintenance::class)->complete($record, ['done_on' => '2026-10-20', 'work_done' => 'Cleared it properly'], $this->t->custodian);
    Carbon::setTestNow('2026-11-25 10:00:00');
    $this->actingAs($this->t->custodian)->post(route('assets.maintenance.reopen', $record), $this->why)
        ->assertSessionHasErrors(['reason_text' => "{$record->number} was done more than 30 days ago. Record new maintenance instead."]);
});
