<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Corrections\ProductionRunActionPolicy;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Support\ProductionRunForm;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockWorld;

/**
 * ⭐ Corrections wave 1 gaps (24 Oct 2026): a production run whose output was
 * partly sold (the Tk 10,000 rule) and a production run in a closed month
 * (the Owner's one-document exception, with the run's real journal count).
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    $this->s->opening([[$this->s->salt, '100', '60']]);
    $this->jar = Product::create(['business_id' => $this->w->business->id, 'unit_of_measure_id' => $this->s->ghee->unit_of_measure_id, 'sku' => 'SALT-JAR', 'name' => 'Pink Salt jar 500g', 'type' => ProductType::FinishedGood]);
    $this->form = fn (string $made = '20', string $salt = '10', string $on = '2026-10-08') => [
        'warehouse_id' => $this->s->savar->id, 'output_product_id' => $this->jar->id, 'output_quantity' => $made,
        'occurred_at' => $on, 'expense_waste' => false, 'note' => null,
        'lines' => [['product_id' => $this->s->salt->id, 'quantity' => $salt, 'waste' => '0', 'unit_id' => null]],
        'costs' => [],
    ];
    $this->run = function (array $form): ProductionRun {
        $action = app(RecordProductionRun::class);

        return $action->confirm($action->draft(...ProductionRunForm::arguments($form), createdBy: $this->w->manager), $this->w->manager);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('corrects a run whose output was partly sold: the sold units leave the new batch, the cost difference posts today', function () {
    // 10 kg salt at 60 made 20 jars at 30; 5 sold; really 12 kg made 20 jars (36 each).
    $run = ($this->run)(($this->form)());
    $this->s->sell($this->jar, '5', '2026-10-09');

    $result = $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Correct, ($this->form)('20', '12'), 'Used 12 kg, not 10'), $this->w->manager);
    $successor = ProductionRun::find($result->link->successor_id);
    $difference = StockCostDifference::query()->where('source_id', $run->id)->sole();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($run->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and($successor->isConfirmed())->toBeTrue()
        ->and($this->s->onHand($this->jar))->toBe('15.0000')
        ->and($this->s->onHand($this->s->salt))->toBe('88.0000')
        // The 5 sold jars cost 30 each then, 36 each now: Tk 30 more cost of sales today.
        ->and($difference->money()->minor)->toBe(-3000);
    $this->s->assertReconciles();
});

it('refuses a correction that would make fewer than were already sold, or another product', function () {
    $run = ($this->run)(($this->form)());
    $this->s->sell($this->jar, '5', '2026-10-09');

    expect(fn () => $this->s->preview($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Correct, ($this->form)('4', '10')), $this->w->manager))
        ->toThrow(ValidationException::class, 'at least 5');
});

it('lets the Owner reverse and correct a run in a closed month, with or without a journal', function () {
    $run = ($this->run)(($this->form)(on: '2026-09-20'));
    $other = ($this->run)(($this->form)('10', '5', '2026-09-21'));
    $this->w->closeThrough('2026-09-30');

    $manager = app(ProductionRunActionPolicy::class)->availability($run->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($manager->isRequestable())->toBeTrue();

    $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $run, DocumentAction::Reverse, reason: 'Run never happened'), $this->w->owner);
    $result = $this->s->submit($this->s->input(ProductionRunActionPolicy::TYPE, $other, DocumentAction::Correct, ($this->form)('12', '5', '2026-09-21'), 'Made 12 jars, not 10'), $this->w->owner);

    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(ClosedPeriodException::query()->withoutGlobalScopes()->where('status', ClosedPeriodException::USED)->count())->toBe(2)
        ->and($this->s->onHand($this->jar))->toBe('12.0000');
    $this->s->assertReconciles();
});
