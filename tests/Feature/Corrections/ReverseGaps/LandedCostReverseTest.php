<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Corrections\LandedCostActionPolicy;
use App\Modules\Purchasing\Models\LandedCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DiscountWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted landed cost is
 * reversed through the corrections engine: its cost comes off the goods still
 * on hand, the part on goods sold since goes to cost of sales today (the
 * Tk 10,000 rule), its journal is mirrored and the bill it raised goes too.
 */
beforeEach(function () {
    $this->d = DiscountWorld::make();
    $this->w = $this->d->w;

    // Salt at Tk 20 a kg, received 10 Oct; freight owed to the supplier, 11 Oct.
    $this->landed = function (string $kg = '100', string $freight = '1000'): LandedCost {
        $order = $this->d->order([[$this->d->salt, $kg, '20.00']]);
        $receipt = $order->goodsReceipts()->with('lines')->latest('id')->firstOrFail();
        $lc = app(SaveLandedCost::class)->handle($receipt, '2026-10-11', [[
            'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
            'goods_receipt_line_id' => null, 'amount' => $freight, 'supplier_id' => $this->d->supplier->id,
            'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Truck from Chattogram',
        ]], $this->w->owner);
        $lc = app(SubmitLandedCost::class)->handle($lc, $this->w->owner);

        return app(PostLandedCost::class)->handle($lc->fresh(['approvalRequest', 'lines']), $this->w->owner);
    };

    $this->sell = function (string $kg, string $on = '2026-10-12') {
        $movements = app(RecordStockOut::class)->handle(product: $this->d->salt, warehouse: $this->d->warehouse, quantity: $kg, performedBy: $this->w->owner, occurredAt: $on);
        $value = StockValue::sum($movements)->absolute();
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($on)->startOfDay(),
            lines: [JournalLineDraft::debit(Account::bySlug('cost_of_goods_sold')->id, $value), JournalLineDraft::credit(Account::bySlug('stock_on_hand')->id, $value)],
            memo: "Test sale of {$kg} kg salt",
            source: $movements->first(),
        ), $this->w->owner);
    };

    $this->input = fn (LandedCost $lc, string $reason = 'Freight was billed to the wrong receipt') => new CorrectionInput(
        LandedCostActionPolicy::TYPE, (int) $lc->id, DocumentAction::Reverse, new ActionReason(null, $reason), null, ReverseDateChoice::OriginalDate, (string) Str::uuid(),
    );

    $this->batch = fn (): StockBatch => StockBatch::query()->where('product_id', $this->d->salt->id)->sole();
    $this->batchValue = fn (): int => StockValue::sum(StockMovement::query()->where('batch_id', ($this->batch)()->id)->get())->minor;

    $this->reconciles = function () {
        expect((int) DB::table('journal_lines')->sum('amount_minor'))->toBe(0)
            ->and(StockLedger::verify())->toBe([]);
        $problems = collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])
            ->filter(fn (string $p) => str_contains($p, 'Stock on Hand') || str_contains($p, 'Accounts Payable'))->values();
        expect($problems->all())->toBe([]);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a posted landed cost with nothing sold: the batch is back at its own cost and the bill goes too', function () {
    $lc = ($this->landed)();
    expect((string) ($this->batch)()->unit_cost)->toBe('30.0000')
        ->and($lc->bills)->toHaveCount(1);
    ($this->reconciles)();

    $service = app(CorrectionService::class);
    $input = ($this->input)($lc);
    $preview = $service->preview($input, $this->w->manager);
    expect($preview->blockers)->toBe([])
        ->and($preview->dates->reversalDate->toDateString())->toBe('2026-10-11');

    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::Executed);

    $lc->refresh();
    expect($lc->isReversed())->toBeTrue()
        ->and($lc->reversal_journal_id)->not->toBeNull()
        ->and($lc->bills()->first()->isReversed())->toBeTrue()
        ->and((string) ($this->batch)()->unit_cost)->toBe('20.0000')
        ->and(($this->batchValue)())->toBe(200000)
        ->and(StockCostDifference::count())->toBe(0);
    ($this->reconciles)();

    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and(CorrectionLink::count())->toBe(1);
});

it('puts the cost on goods sold since through cost of sales today, never re-costing the sales', function () {
    $lc = ($this->landed)();
    ($this->sell)('30');
    $service = app(CorrectionService::class);
    $input = ($this->input)($lc);
    $preview = $service->preview($input, $this->w->manager);

    expect(collect($preview->effects)->pluck('sentence')->implode(' '))->toContain('30 kg of Salt, bulk were already sold or used')->toContain('Tk 300 comes off cost of sales today');
    expect($service->submit($input, $this->w->manager, $preview->previewToken)->outcome)->toBe(CorrectionOutcome::Executed);

    $difference = StockCostDifference::query()->sole();
    expect((string) ($this->batch)()->unit_cost)->toBe('20.0000')
        ->and(($this->batchValue)())->toBe(140000)
        ->and((string) $difference->amount)->toBe('300.00')
        ->and($difference->posted_on->toDateString())->toBe('2026-10-17');
    ($this->reconciles)();
});

it('above Tk 10,000 on goods sold since, only the Owner', function () {
    $lc = ($this->landed)('1000', '50000');
    ($this->sell)('300');
    $policy = app(LandedCostActionPolicy::class);

    $manager = $policy->availability($lc->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($manager->allowed)->toBeFalse()
        ->and($manager->blockers[0]->code)->toBe('sold_stock_owner')
        ->and($policy->availability($lc->fresh(), $this->w->owner, DocumentAction::Reverse)->allowed)->toBeTrue();
});

it('refuses while the bill it raised is paid', function () {
    $lc = ($this->landed)();
    $bill = $lc->bills()->sole();
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->d->supplier, moneyAccount: $this->w->bank, paidOn: '2026-10-16',
        allocations: [['bill' => $bill, 'amount' => '1000', 'discount' => null]], createdBy: $this->w->owner, reference: 'CHQ-9',
    );

    $availability = app(LandedCostActionPolicy::class)->availability($lc->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->message)->toContain('has been paid');
});

it('offers Reverse to whoever may post landed costs, and shows it on the Landed costs screen', function () {
    $lc = ($this->landed)();
    $policy = app(LandedCostActionPolicy::class);

    expect($policy->actionsFor($lc, $this->w->financeB)->actions)->toBe([]);

    $this->actingAs($this->w->manager)->get(route('purchasing.landed-costs.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/landed-costs/index')
            ->where('landedCosts.0.id', $lc->id)
            ->where('landedCosts.0.actions.0.action', 'reverse')
            ->has('reversalReasons'));
});
