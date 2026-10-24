<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Cutover;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Assets\Actions\DisposeFixedAsset;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\Corrections\FixedAssetActionPolicy;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionResult;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a fixed asset on the books
 * (FINAL-PLAN 3.5). Reverse (entered by mistake) runs through the corrections
 * process and mirrors the purchase and every depreciation charge, each on its
 * own date; Dispose takes cost and accumulated depreciation off the books with
 * the gain or loss. Either way the asset is never depreciated again.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);
});

afterEach(fn () => Carbon::setTestNow());

function reverseAsset(FixedAsset $asset, User $by, ?string $key = null): CorrectionResult
{
    $input = new CorrectionInput(
        type: FixedAssetActionPolicy::TYPE,
        documentId: $asset->id,
        action: DocumentAction::Reverse,
        reason: new ActionReason(null, 'Entered by mistake, the van was rented'),
        replacement: null,
        dateChoice: ReverseDateChoice::OriginalDate,
        idempotencyKey: $key ?? (string) Str::uuid(),
    );
    $service = app(CorrectionService::class);

    return $service->submit($input, $by, $service->preview($input, $by)->previewToken);
}

function headNet(string $code): int
{
    return (int) JournalLine::where('account_id', Account::where('code', $code)->sole()->id)->sum('amount_minor');
}

it('reverses the purchase and every depreciation charge on their own dates, and never depreciates it again', function () {
    $asset = $this->p->asset('15000', '2026-07-15');
    foreach ([7, 8, 9] as $month) {
        $this->p->depreciate(2026, $month);
    }

    $result = reverseAsset($asset, $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-07-15');

    $dates = Journal::whereNotNull('reverses_journal_id')->orderBy('journal_date')->pluck('journal_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    expect($dates)->toBe(['2026-07-15', '2026-07-31', '2026-08-31', '2026-09-30']);

    $asset->refresh();
    expect($asset->isReversed())->toBeTrue()
        ->and($asset->reversed_on->toDateString())->toBe('2026-07-15')
        ->and(headNet('1220'))->toBe(0)
        ->and($this->p->balance('accumulated_depreciation'))->toBe(0)
        ->and($this->p->balance('depreciation_expense'))->toBe(0)
        ->and($this->p->balance('cash_at_bank'))->toBe(0)
        ->and(CorrectionLink::count())->toBe(1)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);

    Carbon::setTestNow('2026-11-02 10:00:00');
    $this->p->depreciate(2026, 10);
    expect(FixedAssetDepreciation::count())->toBe(3);

    // A second reverse is refused.
    expect(fn () => reverseAsset($asset->fresh(), $this->w->owner))->toThrow(Exception::class);
});

it('needs a second person above Tk 20,000, also for the Owner', function () {
    $asset = $this->p->asset('120000', '2026-09-01');

    $result = reverseAsset($asset, $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($asset->fresh()->isReversed())->toBeFalse()
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(0);
});

it('offers nothing to somebody without assets.correct and refuses them on the server', function () {
    $asset = $this->p->asset('15000', '2026-07-15');
    $base = '/records/'.FixedAssetActionPolicy::TYPE."/{$asset->id}";
    $body = ['reason_text' => 'Entered by mistake', 'idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'];

    $this->actingAs($this->w->manager)->getJson("{$base}/actions")->assertOk()->assertJsonPath('actions', []);
    $this->actingAs($this->w->manager)->postJson("{$base}/reverse/preview", $body)->assertForbidden();
    $this->actingAs($this->w->manager)->post(route('assets.fixed.dispose', $asset), ['disposed_on' => '2026-10-16', 'method' => 'scrapped', 'reason_text' => 'Broken beyond repair'])->assertForbidden();

    expect($asset->fresh()->isOnTheRegister())->toBeTrue();
});

it('says an opening asset is disposed or corrected in the opening balances, not reversed', function () {
    $asset = app(RecordFixedAsset::class)->handle(
        assetCode: 'FA-OPEN', name: 'Old desk', assetAccount: Account::where('code', '1210')->sole(), acquiredOn: '2026-01-10',
        cost: '30000', usefulLifeMonths: 60, createdBy: $this->w->owner, costInOpeningBalances: true,
    );

    $availability = app(FixedAssetActionPolicy::class)->availability($asset, $this->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('opening_asset')
        ->and(app(FixedAssetActionPolicy::class)->availability($asset, $this->w->owner, DocumentAction::Dispose)->allowed)->toBeTrue();
})->skip(fn () => Cutover::date()->isBefore(Carbon::parse('2026-01-10')), 'cutover is before the test date');

it('refuses a reverse while a depreciation charge sits in a closed month', function () {
    $asset = $this->p->asset('15000', '2026-07-15');
    $this->p->depreciate(2026, 7);
    $this->p->depreciate(2026, 8);
    $this->w->closeThrough('2026-08-31');

    $availability = app(FixedAssetActionPolicy::class)->availability($asset, $this->w->owner, DocumentAction::Reverse);

    expect(collect($availability->blockers)->pluck('code')->all())->toContain('closed_depreciation');
});

it('disposes of a sold asset with the gain, takes it off the books and stops depreciation', function () {
    $asset = $this->p->asset('15000', '2026-07-15');
    foreach ([7, 8, 9] as $month) {
        $this->p->depreciate(2026, $month);
    }
    $book = DisposeFixedAsset::figures($asset->fresh());
    $gainHead = Account::where('code', '4990')->sole();

    $this->actingAs($this->w->owner)->post(route('assets.fixed.dispose', $asset), [
        'disposed_on' => '2026-10-16', 'method' => 'sold', 'proceeds' => '12000',
        'money_account_id' => $this->w->bank->id, 'gain_loss_account_id' => $gainHead->id,
        'reason_text' => 'Sold to Karim Motors',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $asset->refresh();
    $journal = Journal::findOrFail($asset->disposal_journal_id);
    $gain = 1200000 - $book['book_value_minor'];

    expect($asset->isDisposed())->toBeTrue()
        ->and($asset->disposal_method)->toBe('sold')
        ->and($journal->journal_date->toDateString())->toBe('2026-10-16')
        ->and((int) $journal->lines()->sum('amount_minor'))->toBe(0)
        ->and(headNet('1220'))->toBe(0)
        ->and($this->p->balance('accumulated_depreciation'))->toBe(0)
        ->and(headNet('4990'))->toBe(-$gain)
        ->and($this->p->balance('cash_at_bank'))->toBe(1200000 - 1500000)
        ->and(app(FixedAssetActionPolicy::class)->availability($asset, $this->w->owner, DocumentAction::Reverse)->allowed)->toBeFalse();

    Carbon::setTestNow('2026-11-02 10:00:00');
    $this->p->depreciate(2026, 10);
    expect(FixedAssetDepreciation::count())->toBe(3);
});

it('books a scrapped asset\'s loss to an expense head and refuses what does not add up', function () {
    $asset = $this->p->asset('15000', '2026-07-15');
    $this->p->depreciate(2026, 7);
    $dispose = app(DisposeFixedAsset::class);
    $why = new ActionReason(null, 'Broken beyond repair');
    $loss = Account::query()->where('type', 'expense')->where('is_postable', true)->orderBy('code')->firstOrFail();

    // No head, an income head for a loss, a date before the last charge.
    expect(fn () => $dispose->handle($asset, '2026-10-16', 'scrapped', '0', null, null, $this->w->owner, $why))->toThrow(ValidationException::class)
        ->and(fn () => $dispose->handle($asset, '2026-10-16', 'scrapped', '0', null, Account::where('code', '4990')->sole(), $this->w->owner, $why))->toThrow(ValidationException::class)
        ->and(fn () => $dispose->handle($asset, '2026-07-20', 'scrapped', '0', null, $loss, $this->w->owner, $why))->toThrow(ValidationException::class)
        ->and(fn () => $dispose->handle($asset, '2026-10-16', 'scrapped', '0', null, $loss, $this->w->financeB, $why))->toThrow(ValidationException::class);

    $dispose->handle($asset, '2026-10-16', 'scrapped', '0', null, $loss, $this->w->owner, $why);

    expect(headNet('1220'))->toBe(0)
        ->and((int) JournalLine::where('account_id', $loss->id)->sum('amount_minor'))->toBe(1500000 - 125000)
        ->and(fn () => $dispose->handle($asset, '2026-10-16', 'scrapped', '0', null, $loss, $this->w->owner, $why))->toThrow(ValidationException::class);
});
