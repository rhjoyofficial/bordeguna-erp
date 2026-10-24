<?php

use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use Illuminate\Support\Carbon;
use Tests\Support\ThingsWorld;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): Reverse import (FINAL-PLAN 3.5 and
 * section 2, example 3: the chairs sheet imported twice). Within 7 days; the
 * sheet's own hand-overs are reversed; untouched things go to the bin; a
 * thing used since is kept, with its reason, and so is anything sharing a
 * sheet hand-over with it.
 */
beforeEach(function () {
    $this->t = ThingsWorld::make();
    $this->w = $this->t->w;
    $this->sheet = fn () => $this->t->import([
        ['category' => 'Furniture', 'name' => 'Office chair', 'place' => 'Head office'],
        ['category' => 'Furniture', 'name' => 'Office chair', 'place' => 'Head office'],
        ['category' => 'IT', 'name' => 'Laptop', 'place' => 'Head office', 'holder' => $this->t->rakib->employee_code],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a sheet imported twice: its hand-over is reversed and its things go to the bin, tags reserved', function () {
    ($this->sheet)();
    $twice = ($this->sheet)();

    expect(AssetItem::query()->count())->toBe(6)->and($twice->created_count)->toBe(3);

    $page = $this->actingAs($this->t->custodian)->get(route('assets.items.import'))->assertOk()->original->getData()['page']['props'];
    $row = collect($page['batches'])->firstWhere('number', $twice->number);
    expect($row['plan']['remove'])->toHaveCount(3)->and($row['plan']['keep'])->toBe([]);

    $this->actingAs($this->t->custodian)->post(route('assets.items.import.reverse', $twice), [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->sales)->post(route('assets.items.import.reverse', $twice), ['reason_text' => 'Imported twice'])->assertForbidden();
    $this->actingAs($this->t->custodian)->post(route('assets.items.import.reverse', $twice), ['reason_text' => 'Imported twice'])->assertSessionHas('success');

    $sheetLaptop = AssetItem::onlyTrashed()->where('name', 'Laptop')->sole();
    $handOver = AssetMovement::query()->where('import_batch_id', $twice->id)->sole();

    expect(AssetItem::query()->count())->toBe(3)
        ->and(AssetItem::onlyTrashed()->count())->toBe(3)
        ->and($handOver->isReversed())->toBeTrue()
        ->and($sheetLaptop->custodian_employee_id)->toBeNull()
        ->and($twice->fresh()->reversed_at)->not->toBeNull();

    // Once only.
    $this->actingAs($this->t->custodian)->post(route('assets.items.import.reverse', $twice), ['reason_text' => 'Imported twice'])
        ->assertSessionHasErrors(['reason_text' => "Import {$twice->number} was already reversed."]);

    // The next thing never reuses a reserved tag.
    expect($this->t->thing('New laptop')->tag)->toBe('BDG-IT-0003');
});

it('keeps a thing used since, with its reason, and refuses after 7 days', function () {
    $batch = ($this->sheet)();
    $chair = AssetItem::query()->where('name', 'Office chair')->orderBy('id')->first();
    $this->t->move(AssetMovementKind::Issue, [['item' => $chair]], ['employee_id' => $this->t->nazmul->id], '2026-10-20');

    $this->actingAs($this->t->custodian)->post(route('assets.items.import.reverse', $batch), ['reason_text' => 'Wrong sheet'])->assertSessionHas('success');

    expect($chair->fresh()->trashed())->toBeFalse()
        ->and(AssetItem::onlyTrashed()->count())->toBe(2);

    $later = ($this->sheet)();
    Carbon::setTestNow('2026-10-28 10:00:00');
    $this->actingAs($this->t->custodian)->post(route('assets.items.import.reverse', $later), ['reason_text' => 'Wrong sheet'])
        ->assertSessionHasErrors(['reason_text' => "Import {$later->number} is more than 7 days old. Delete or dispose of its things one by one."]);
});
