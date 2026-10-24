<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Inventory\Actions\ApproveCropWriteOff;
use App\Modules\Inventory\Actions\RaiseCropWriteOff;
use App\Modules\Inventory\Actions\RecordCropHarvest;
use App\Modules\Inventory\Corrections\CropHarvestActionPolicy;
use App\Modules\Inventory\Corrections\CropWriteOffActionPolicy;
use App\Modules\Inventory\database\seeders\CropWriteOffWorkflowSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\CropHarvest;
use App\Modules\Inventory\Models\CropWriteOff;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\CropHeads;
use App\Modules\Inventory\Support\StockValue;
use App\Support\ApprovalCards;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\StockWorld;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): the season's cost on 1152 Crops in
 * Progress becomes paddy stock, or a loss with the Owner's approval, and
 * either is reversed through the corrections engine.
 *
 * The live case: Tk 4,000 seed and Tk 17,575 cultivation on Masum land's
 * head; 1,200 kg of brown paddy harvested.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    (new CropWriteOffWorkflowSeeder)->run();

    $this->head = Account::create([
        'code' => '1152', 'name' => 'Crops in Progress - Brown Rice (Masum land)', 'type' => AccountType::Asset,
        'parent_id' => Account::where('code', '1100')->value('id'), 'is_postable' => true,
    ]);
    $this->loss = Account::create([
        'code' => '5210', 'name' => 'Crop Failure Loss', 'type' => AccountType::Expense,
        'parent_id' => Account::where('code', '5000')->value('id'), 'is_postable' => true,
    ]);

    $kg = UnitOfMeasure::create(['business_id' => $this->w->business->id, 'name' => 'Kilogram (farm)', 'abbreviation' => 'kgf']);
    $bundle = UnitOfMeasure::create(['business_id' => $this->w->business->id, 'name' => 'Bundle', 'abbreviation' => 'bdl']);
    $this->paddy = Product::create(['business_id' => $this->w->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'RM-PADDY-BROWN', 'name' => 'Brown paddy', 'type' => ProductType::RawMaterial]);
    $this->straw = Product::create(['business_id' => $this->w->business->id, 'unit_of_measure_id' => $bundle->id, 'sku' => 'RM-STRAW', 'name' => 'Rice straw', 'type' => ProductType::RawMaterial]);
});

afterEach(fn () => Carbon::setTestNow());

/** Spending on the season, as a payment voucher would post it (Dr 1152 / Cr capital here). */
function fundCrop(Account $head, string $taka, string $on, $by): void
{
    app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse($on)->startOfDay(),
        lines: [
            JournalLineDraft::debit($head->id, Money::ofTaka($taka)),
            JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka($taka)),
        ],
        memo: "Season cost {$taka}",
    ), $by);
}

function harvest(object $t, string $quantity, ?string $amount = null, array $byProducts = [], string $on = '2026-10-15', ?string $key = null, $by = null): CropHarvest
{
    return app(RecordCropHarvest::class)->handle(
        harvestedOn: $on,
        cropAccount: $t->head,
        warehouse: $t->s->savar,
        lines: [['product' => $t->paddy, 'quantity' => $quantity], ...$byProducts],
        createdBy: $by ?? $t->w->manager,
        amount: $amount,
        idempotencyKey: $key,
    );
}

function headMinor(Account $head): int
{
    return (int) DB::table('journal_lines')->where('account_id', $head->id)->sum('amount_minor');
}

it('moves the whole season into paddy stock: the head reads zero and stock equals the ledger', function () {
    fundCrop($this->head, '4000', '2026-08-24', $this->w->owner);
    fundCrop($this->head, '17575', '2026-10-04', $this->w->owner);

    $harvest = harvest($this, '1200');

    $batch = StockBatch::where('product_id', $this->paddy->id)->sole();
    expect($harvest->amount_paisa)->toBe(2_157_500)
        ->and($batch->current_quantity)->toBe('1200.0000')
        ->and($batch->unit_cost)->toBe('17.9792')
        ->and($batch->received_at->toDateString())->toBe('2026-10-15')
        ->and(headMinor($this->head))->toBe(0);

    // Dr Stock on Hand the batch value; Cr 1152 the amount to the paisa; the
    // 4 paisa the 4dp unit cost cannot carry go to Rounding, never hidden.
    $journal = Journal::findOrFail($harvest->journal_id);
    expect((int) $journal->lines()->sum('amount_minor'))->toBe(0)
        ->and($journal->journal_date->toDateString())->toBe('2026-10-15')
        ->and((int) $journal->lines()->where('account_id', Account::bySlug('stock_on_hand')->id)->sum('amount_minor'))
        ->toBe(StockValue::of($harvest->lines->first()->stockMovement)->minor);

    $this->s->assertReconciles();
});

it('harvests part of the head, with straw taking the share it is given', function () {
    fundCrop($this->head, '21575', '2026-10-04', $this->w->owner);

    $harvest = harvest($this, '600', '10000', [['product' => $this->straw, 'quantity' => '50', 'cost' => '1000']]);

    $paddy = StockBatch::where('product_id', $this->paddy->id)->sole();
    $straw = StockBatch::where('product_id', $this->straw->id)->sole();

    expect($paddy->unit_cost)->toBe('15.0000')
        ->and($straw->unit_cost)->toBe('20.0000')
        ->and($straw->current_quantity)->toBe('50.0000')
        ->and($harvest->lines)->toHaveCount(2)
        ->and(headMinor($this->head))->toBe(1_157_500)
        ->and(CropHeads::balanceMinor($this->head))->toBe(1_157_500);

    $this->s->assertReconciles();
});

it('refuses more than the head holds, judged from the harvest day on', function () {
    fundCrop($this->head, '10000', '2026-10-04', $this->w->owner);

    expect(fn () => harvest($this, '100', '10000.01'))->toThrow(ValidationException::class, 'holds only Tk 10,000.00');

    // A write-off dated later already took 4,000: a harvest back-dated before
    // it may take only what is left after it.
    $writeOff = app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, $this->loss, '4000', 'Flood took the low plot', $this->w->manager);
    app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->owner);

    expect(fn () => harvest($this, '100', '7000', on: '2026-10-08'))->toThrow(ValidationException::class, 'holds only Tk 6,000.00');

    expect(CropHarvest::count())->toBe(0)->and(StockBatch::where('product_id', $this->paddy->id)->count())->toBe(0);
});

it('refuses a head that is not a crops in progress head, an empty head, and a by-product taking everything', function () {
    fundCrop($this->head, '5000', '2026-10-04', $this->w->owner);

    expect(fn () => app(RecordCropHarvest::class)->handle('2026-10-15', Account::bySlug('accounts_receivable'), $this->s->savar, [['product' => $this->paddy, 'quantity' => '10']], $this->w->manager))
        ->toThrow(ValidationException::class, 'Crops in Progress head');

    expect(fn () => harvest($this, '10', on: '2026-10-01'))->toThrow(ValidationException::class, 'holds nothing');

    expect(fn () => harvest($this, '10', '5000', [['product' => $this->straw, 'quantity' => '5', 'cost' => '5000']]))
        ->toThrow(ValidationException::class, 'main product must keep a share');

    // A neighbouring asset head outside 1152 never passes.
    $group = Account::create(['code' => '1153', 'name' => 'Not a crop', 'type' => AccountType::Asset, 'parent_id' => Account::where('code', '1100')->value('id'), 'is_postable' => true]);
    expect(CropHeads::allows($group))->toBeFalse()->and(CropHeads::allows($this->head))->toBeTrue();
});

it('returns the first harvest to a double click with the same key', function () {
    fundCrop($this->head, '5000', '2026-10-04', $this->w->owner);

    $first = harvest($this, '100', '1000', key: 'popup-key-1');
    $again = harvest($this, '100', '1000', key: 'popup-key-1');

    expect($again->id)->toBe($first->id)->and(CropHarvest::count())->toBe(1)->and(headMinor($this->head))->toBe(400_000);
});

it('refuses a harvest dated in a closed month', function () {
    fundCrop($this->head, '5000', '2026-09-10', $this->w->owner);
    $this->w->closeThrough('2026-09-30');

    expect(fn () => harvest($this, '100', on: '2026-09-20'))->toThrow(ValidationException::class);
    expect(CropHarvest::count())->toBe(0);
});

it('reverses an untouched harvest on its own date: batch empty, the season back on the head', function () {
    fundCrop($this->head, '15000', '2026-10-04', $this->w->owner);
    $harvest = harvest($this, '1000');

    $result = $this->s->submit($this->s->input(CropHarvestActionPolicy::TYPE, $harvest, DocumentAction::Reverse, reason: 'Wrong plot chosen'), $this->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($harvest->fresh()->isReversed())->toBeTrue()
        ->and(StockBatch::where('product_id', $this->paddy->id)->sole()->current_quantity)->toBe('0.0000')
        ->and(headMinor($this->head))->toBe(1_500_000)
        ->and(Journal::findOrFail($harvest->fresh()->reversal_journal_id)->journal_date->toDateString())->toBe('2026-10-15');

    $this->s->assertReconciles();

    // Reversed once is reversed: the button stays, greyed, with its reason.
    $again = app(CropHarvestActionPolicy::class)->availability($harvest->fresh(), $this->w->manager, DocumentAction::Reverse);
    expect($again->allowed)->toBeFalse()->and($again->blockers[0]->code)->toBe('already_reversed');
});

it('refuses to reverse a harvest some of which is sold, with a sentence', function () {
    fundCrop($this->head, '15000', '2026-10-04', $this->w->owner);
    $harvest = harvest($this, '1000');
    $this->s->sell($this->paddy, '10', '2026-10-16');

    $availability = app(CropHarvestActionPolicy::class)->availability($harvest->fresh(), $this->w->manager, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('stock_moved')
        ->and(collect($availability->blockers)->firstWhere('code', 'stock_moved')->message)->toContain('cannot simply be reversed');
});

it('lets only the Owner reverse a harvest in a closed month, on its own date', function () {
    fundCrop($this->head, '15000', '2026-09-10', $this->w->owner);
    $harvest = harvest($this, '1000', on: '2026-09-20');
    $this->w->closeThrough('2026-09-30');

    $policy = app(CropHarvestActionPolicy::class);
    expect($policy->availability($harvest->fresh(), $this->w->manager, DocumentAction::Reverse)->allowed)->toBeFalse();

    $result = $this->s->submit($this->s->input(CropHarvestActionPolicy::TYPE, $harvest, DocumentAction::Reverse, reason: 'Counted twice'), $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(Journal::findOrFail($harvest->fresh()->reversal_journal_id)->journal_date->toDateString())->toBe('2026-09-20')
        ->and(headMinor($this->head))->toBe(1_500_000);

    $this->s->assertReconciles();
});

it('waits for the Owner before a crop failure posts anything, then posts Dr loss / Cr the head', function () {
    fundCrop($this->head, '15000', '2026-10-04', $this->w->owner);

    $writeOff = app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, $this->loss, '6000', 'Flood on the low plot', $this->w->manager);

    expect($writeOff->status)->toBe(CropWriteOff::WAITING)
        ->and($writeOff->journal_id)->toBeNull()
        ->and(headMinor($this->head))->toBe(1_500_000);

    // The card reaches the Owner, and the Manager cannot decide it.
    $request = ApprovalRequest::where('approvable_type', $writeOff->getMorphClass())->sole();
    expect($request->canBeActionedBy($this->w->owner))->toBeTrue()
        ->and($request->canBeActionedBy($this->w->manager))->toBeFalse()
        ->and(app(ApprovalCards::class)->present($request->fresh())['approve_url'])->toContain("crop-write-offs/{$writeOff->id}/approve");
    expect(fn () => app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->manager))->toThrow(RuntimeException::class);

    $approved = app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->owner);

    expect($approved->status)->toBe(CropWriteOff::APPROVED)
        ->and(headMinor($this->head))->toBe(900_000)
        ->and((int) DB::table('journal_lines')->where('account_id', $this->loss->id)->sum('amount_minor'))->toBe(600_000);

    $this->s->assertReconciles();

    // Reverse it: the cost is back on the head and off the loss.
    $result = $this->s->submit($this->s->input(CropWriteOffActionPolicy::TYPE, $approved, DocumentAction::Reverse, reason: 'Crop recovered after all'), $this->w->manager);
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(headMinor($this->head))->toBe(1_500_000)
        ->and((int) DB::table('journal_lines')->where('account_id', $this->loss->id)->sum('amount_minor'))->toBe(0);
});

it('refuses a write-off above the balance, asks again at approval, and a rejection posts nothing', function () {
    fundCrop($this->head, '5000', '2026-10-04', $this->w->owner);

    expect(fn () => app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, $this->loss, '5000.01', 'Pests', $this->w->manager))
        ->toThrow(ValidationException::class, 'holds only');
    expect(fn () => app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, Account::bySlug('owners_capital'), '100', 'Pests', $this->w->manager))
        ->toThrow(ValidationException::class, 'expense head');

    $writeOff = app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, $this->loss, '4000', 'Pests in the north plot', $this->w->manager);

    // A harvest meanwhile takes most of the head: approval refuses, still waiting.
    harvest($this, '100', '3000');
    expect(fn () => app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->owner, 'ok'))->toThrow(ValidationException::class, 'holds only');
    expect($writeOff->fresh()->status)->toBe(CropWriteOff::WAITING)->and($writeOff->fresh()->approvalRequest->isPending())->toBeTrue();

    $rejected = app(ApproveCropWriteOff::class)->reject($writeOff, $this->w->owner, 'Raise the right amount', app(RejectApprovable::class));
    expect($rejected->status)->toBe(CropWriteOff::REJECTED)
        ->and($rejected->journal_id)->toBeNull()
        ->and(app(CropWriteOffActionPolicy::class)->availability($rejected, $this->w->owner, DocumentAction::Reverse)->allowed)->toBeFalse();
});

it('lets the Owner approve his own write-off only with a note', function () {
    fundCrop($this->head, '5000', '2026-10-04', $this->w->owner);
    $writeOff = app(RaiseCropWriteOff::class)->handle('2026-10-12', $this->head, $this->loss, '1000', 'Hail damage', $this->w->owner);

    expect(fn () => app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->owner))->toThrow(ValidationException::class);
    expect(app(ApproveCropWriteOff::class)->handle($writeOff, $this->w->owner, 'I saw the field myself')->status)->toBe(CropWriteOff::APPROVED);
});

it('gives the screen and its actions to Owner and Manager only', function () {
    $this->withoutVite();
    fundCrop($this->head, '5000', '2026-10-04', $this->w->owner);

    $this->actingAs($this->s->staff)->get('/inventory/harvests')->assertForbidden();
    $this->actingAs($this->w->financeA)->post('/inventory/harvests', [])->assertForbidden();

    $this->actingAs($this->w->manager)->get('/inventory/harvests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/harvests/index')
            ->has('heads', 1)
            ->where('heads.0.balance', '5000.00')
            ->where('defaultLossHeadId', $this->loss->id));

    $this->actingAs($this->w->manager)->post('/inventory/harvests', [
        'harvested_on' => '2026-10-15',
        'crop_account_id' => $this->head->id,
        'warehouse_id' => $this->s->savar->id,
        'amount' => null,
        'idempotency_key' => 'screen-1',
        'lines' => [['product_id' => $this->paddy->id, 'quantity' => '250', 'unit_id' => null]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(CropHarvest::sole()->amount_paisa)->toBe(500_000)->and(headMinor($this->head))->toBe(0);

    $this->actingAs($this->w->manager)->get('/inventory/harvests')
        ->assertInertia(fn (Assert $page) => $page->where('harvests.0.actions.0.action', 'reverse')->where('harvests.0.actions.0.allowed', true));
});
