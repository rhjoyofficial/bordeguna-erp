<?php

use App\Modules\Accounting\Actions\AuthoriseOwnerWithdrawal;
use App\Modules\Accounting\Actions\PostOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveOwnerWithdrawal;
use App\Modules\Accounting\Corrections\OwnerWithdrawalActionPolicy;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Support\OwnerWithdrawalsMonthlyReport;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Support\Carbon;
use Tests\Support\StockWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted owner withdrawal is
 * reversed through the corrections engine: the goods go back onto the batch
 * they left and the drawings journal is mirrored.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    $this->s->opening([[$this->s->ghee, '20', '900']]);
    $this->w->business->update(['proprietor_user_id' => $this->w->owner->id]);

    $this->posted = function (string $quantity = '2', string $on = '2026-10-15'): OwnerWithdrawal {
        $draft = app(SaveOwnerWithdrawal::class)->handle($this->s->savar, $on, [['product_id' => $this->s->ghee->id, 'quantity' => $quantity]], $this->w->owner);
        app(AuthoriseOwnerWithdrawal::class)->handle($draft, $this->w->financeA, null);

        return app(PostOwnerWithdrawal::class)->handle($draft->fresh(), $this->w->owner);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a posted withdrawal: the goods are back on their batch and the drawings come down', function () {
    $withdrawal = ($this->posted)('2');
    $drawings = $this->s->balance('owners_drawings');
    expect($this->s->onHand($this->s->ghee))->toBe('18.0000')
        ->and($drawings)->toBe(180000);

    $input = $this->s->input(OwnerWithdrawalActionPolicy::TYPE, $withdrawal, DocumentAction::Reverse, reason: 'Moin put the jars back');
    $preview = $this->s->preview($input, $this->w->financeB);
    expect($preview->blockers)->toBe([])
        ->and($preview->dates->reversalDate->toDateString())->toBe('2026-10-15');

    $service = app(CorrectionService::class);
    expect($service->submit($input, $this->w->financeB, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::Executed);

    $withdrawal->refresh();
    expect($withdrawal->isReversed())->toBeTrue()
        ->and($withdrawal->reversal_reason)->toBe('Moin put the jars back')
        ->and($withdrawal->reversal_journal_id)->not->toBeNull()
        ->and($this->s->onHand($this->s->ghee))->toBe('20.0000')
        ->and($this->s->balance('owners_drawings'))->toBe(0)
        ->and(OwnerWithdrawalsMonthlyReport::months())->toBe([]);
    $this->s->assertReconciles();

    // A double click answers the same and posts nothing more.
    expect($service->submit($input, $this->w->financeB, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and(CorrectionLink::count())->toBe(1)
        ->and($this->s->onHand($this->s->ghee))->toBe('20.0000');
});

it('refuses when the batch was re-costed since', function () {
    $withdrawal = ($this->posted)('2');
    $batch = StockBatch::query()->findOrFail($withdrawal->lines()->sole()->stock_batch_id);
    app(RevalueStockBatch::class)->handle(batch: $batch, direction: MovementDirection::In, newUnitCost: '950.0000', valueDelta: '900.00', performedBy: $this->w->owner->id, note: 'Late freight', occurredAt: Carbon::parse('2026-10-16'));

    $availability = app(OwnerWithdrawalActionPolicy::class)->availability($withdrawal->fresh(), $this->w->financeB, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->message)->toContain('re-costed since');
});

it('offers Reverse only on a posted withdrawal, to Owner and Finance; a closed month is the Owner\'s', function () {
    $policy = app(OwnerWithdrawalActionPolicy::class);
    $draft = app(SaveOwnerWithdrawal::class)->handle($this->s->savar, '2026-09-20', [['product_id' => $this->s->ghee->id, 'quantity' => '1']], $this->w->owner);
    expect($policy->actionsFor($draft, $this->w->owner)->actions)->toBe([]);

    $withdrawal = ($this->posted)('1', '2026-09-20');
    expect($policy->actionsFor($withdrawal, $this->w->manager)->actions)->toBe([])
        ->and($policy->actionsFor($withdrawal, $this->w->financeB)->actions[0]->allowed)->toBeTrue();

    $this->w->closeThrough('2026-09-30');
    expect($policy->availability($withdrawal->fresh(), $this->w->owner, DocumentAction::Reverse)->allowed)->toBeTrue()
        ->and($policy->availability($withdrawal->fresh(), $this->w->financeB, DocumentAction::Reverse)->allowed)->toBeFalse();
});

it('shows the Reverse button on the owner withdrawals screen', function () {
    $withdrawal = ($this->posted)('1');

    $this->actingAs($this->w->owner)->get(route('accounting.owner-withdrawals.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/owner-withdrawals/index')
            ->where('withdrawals.0.id', $withdrawal->id)
            ->where('withdrawals.0.actions.0.action', 'reverse')
            ->has('reversalReasons'));
});
