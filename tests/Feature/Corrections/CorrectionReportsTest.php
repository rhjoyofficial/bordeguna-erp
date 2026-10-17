<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\AccountLedger;
use App\Modules\Accounting\Support\AccountsOverview;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Corrections\CorrectionsReport;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\CorrectionService;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;

/**
 * No double counting after a correction (PHASE-0-CONTRACT v2, 1.12; 3.7 tests
 * 30 to 34; ChatGPT's 41). Money from the ledger; lists and counts from the
 * Reversible scopes; journal lists show every entry unless the reader hides
 * the reversal pairs (Joy, 12 Oct 2026).
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('30/41. Accounts Overview: record 5,000, correct to 4,500: other income for the month = 4,500, count = 1 live', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $this->w->correct($voucher, $this->w->financeB, ['amount' => '4500', 'received_on' => '2026-10-14']);

    $counts = (new AccountsOverview($this->w->business->id))->voucherCounts(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), Carbon::parse('2026-10-17'));

    expect($counts['other_income'])->toBe(['count' => 1, 'minor' => 450_000]);

    // A reversal alone: the month shows nothing.
    $other = $this->w->record('700', '2026-10-16');
    $this->w->reverse($other, $this->w->financeB);
    $counts = (new AccountsOverview($this->w->business->id))->voucherCounts(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), Carbon::parse('2026-10-17'));
    expect($counts['other_income'])->toBe(['count' => 1, 'minor' => 450_000]);
});

it('31. Income Statement and Trial Balance: this month and the next change only by the correction\'s true difference', function () {
    $voucher = $this->w->record('5000', '2026-09-20');

    $incomeSep = fn () => IncomeStatement::between(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->totalIncome()->minor;
    $incomeOct = fn () => IncomeStatement::between(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->totalIncome()->minor;
    [$sepBefore, $octBefore] = [$incomeSep(), $incomeOct()];

    $this->w->correct($voucher, $this->w->financeB, ['amount' => '4500']);

    expect(abs($incomeSep() - $sepBefore))->toBe(50_000)
        ->and($incomeOct())->toBe($octBefore)
        ->and(TrialBalance::between(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->balances())->toBeTrue()
        ->and(TrialBalance::between(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->balances())->toBeTrue();
});

it('32. GL and Day book: the pair is shown by default and hidden with the toggle', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $this->w->correct($voucher, $this->w->financeB, ['amount' => '4500']);

    $from = Carbon::parse('2026-10-01');
    $to = Carbon::parse('2026-10-31');
    $bank = Account::bySlug('cash_at_bank');

    $shown = AccountLedger::for($bank, $from, $to);
    $hidden = AccountLedger::for($bank, $from, $to, hideReversedPairs: true);
    expect($shown->hiddenJournals)->toBe(0)
        ->and($hidden->hiddenJournals)->toBe(2);

    $day = fn (bool $hide) => $this->actingAs($this->w->owner)
        ->get('/accounting/day-book?from=2026-10-01&to=2026-10-31'.($hide ? '&hide_reversed=1' : ''))
        ->assertOk()
        ->viewData('page')['props'];

    expect($day(false)['count'])->toBe(3)
        ->and($day(false)['hide_reversed'])->toBeFalse()
        ->and($day(true)['count'])->toBe(1);
});

it('34. the Corrections report lists the correction, the request (waiting, then approved), the closed-month exception, a blocked submit and a duplicate acknowledgement', function () {
    // A plain correction.
    $a = $this->w->record('5000', '2026-10-15');
    $this->w->correct($a, $this->w->financeB, ['amount' => '4500']);

    // A request above the limit.
    $big = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $request = $this->w->correct($big, $this->w->financeB, ['amount' => '25000'])->request;

    $report = app(CorrectionsReport::class);
    $kinds = fn () => $report->forMonth($this->w->business->id, Carbon::parse('2026-10-01'))->pluck('kind')->all();
    expect($kinds())->toContain('corrected')->toContain('waiting_second_person');

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $request));
    expect($kinds())->toContain('approved');

    // A blocked submit.
    try {
        $this->w->reverse($a->fresh(), $this->w->financeC);
    } catch (CorrectionBlockedException) {
    }

    // A closed-month exception by the Owner.
    $sep = $this->w->record('800', '2026-09-15');
    $this->w->closeThrough('2026-09-30');
    $this->w->reverse($sep, $this->w->owner);

    // Saved despite a possible duplicate.
    $this->w->record('4500', '2026-10-13', head: $this->w->interest);
    $target = $this->w->record('9000', '2026-10-15');
    $input = $this->w->input($target, DocumentAction::Correct, $this->w->replacement($target, ['account_id' => $this->w->interest->id, 'amount' => '4500']));
    $preview = $this->w->preview($input, $this->w->financeB);
    try {
        app(CorrectionService::class)->submit($input, $this->w->financeB, $preview->previewToken);
    } catch (PossibleDuplicateException $e) {
        app(CorrectionService::class)->submit($input, $this->w->financeB, $preview->previewToken, $e->warning->ackToken);
    }

    $rows = $report->forMonth($this->w->business->id, Carbon::parse('2026-10-01'));
    $closed = $rows->firstWhere('kind', 'closed_month_exception');

    expect($rows->pluck('kind')->unique()->values()->all())->toContain('corrected', 'reversed', 'approved', 'blocked_attempt', 'closed_month_exception', 'saved_despite_duplicate', 'period_closed')
        ->and($closed['closed_period_exception'])->toBeTrue()
        ->and($rows->firstWhere('kind', 'reversed')['closed_period_exception'])->toBeTrue()
        ->and($rows->firstWhere('kind', 'blocked_attempt')['actor'])->toBe('Moin')
        ->and($report->summary($this->w->business->id, Carbon::parse('2026-10-01'))['corrected'])->toBe(3);

    // Another business sees none of it.
    expect($report->forMonth($this->w->other->id, Carbon::parse('2026-10-01'))->where('kind', '!=', 'period_closed')->count())->toBe(0);

    // The page: Owner and Finance; not the Manager.
    $this->actingAs($this->w->financeC)->get('/accounting/corrections?month=2026-10')->assertOk();
    $this->actingAs($this->w->manager)->get('/accounting/corrections?month=2026-10')->assertForbidden();
});

it('the History tab shows the correction both ways and says when history starts', function () {
    $voucher = $this->w->record('5000', '2026-10-15');
    $successor = $this->w->correct($voucher, $this->w->financeB, ['amount' => '4500'])->successor;

    $history = $this->actingAs($this->w->financeC)
        ->getJson('/records/accounting.income_voucher/'.$voucher->id.'/history')
        ->assertOk()->json();

    expect(collect($history['entries'])->pluck('kind')->all())->toContain('corrected', 'created')
        ->and(collect($history['entries'])->firstWhere('kind', 'corrected')['summary'])->toContain('Corrected by Shuborna')
        ->and($history['gap_notice'])->toBeNull();

    $succ = $this->actingAs($this->w->financeC)->getJson('/records/accounting.income_voucher/'.$successor->id.'/history')->json();
    expect(collect($succ['entries'])->firstWhere('kind', 'correction_of')['summary'])->toBe("Correction of {$voucher->voucher_number}");

    $this->actingAs($this->w->manager)->getJson('/records/accounting.income_voucher/'.$voucher->id.'/history')->assertNotFound();
});
