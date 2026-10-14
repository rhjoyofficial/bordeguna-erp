<?php

use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Actions\Loans\LoanAmendments;
use App\Modules\Accounting\Actions\Loans\LoanWriteOffs;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanAmendment;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanScheduleRow;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LoanWorld;

/**
 * Changes that need the Owner (loans plan v2, phase 2): a principal write-off
 * and an interest waiver, never one for the other (ChatGPT review, point 9),
 * and a restructure that keeps the old terms and schedule as history.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->w = LoanWorld::make();
    $this->loan = $this->w->approved();
    $this->w->advance($this->loan, '120000', '2026-01-01');
    $this->evidence = fn () => [UploadedFile::fake()->create('settlement-letter.pdf', 20, 'application/pdf')];
    $this->ask = fn (array $data, $loan = null) => app(LoanWriteOffs::class)->request(($loan ?? $this->loan)->fresh(), $data + ['effective_date' => '2026-02-01', 'reason' => 'Borrower died; estate has nothing'], ($this->evidence)(), $this->w->finance);
    $this->onHead = fn (LoanEntry $entry, string $slug) => (int) JournalLine::query()->where('journal_id', $entry->journal_id)->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('writes principal off only with the Owner\'s approval, a reason and evidence: Dr 6406, Cr the loan head', function () {
    expect(LoanWorld::refusal(fn () => app(LoanWriteOffs::class)->request($this->loan->fresh(), ['kind' => 'principal_write_off', 'amount' => '1000', 'effective_date' => '2026-02-01', 'reason' => 'x'], [], $this->w->finance)))
        ->toContain('Attach the evidence')
        ->and(LoanWorld::refusal(fn () => ($this->ask)(['kind' => 'principal_write_off', 'amount' => '120000.01'])))->toContain('Write off between nothing and the principal outstanding');

    $writeOff = ($this->ask)(['kind' => 'principal_write_off', 'amount' => '120000']);

    // Asked for: nothing booked yet, and it waits for the Owner alone.
    expect($writeOff->status)->toBe(LoanWriteOff::PENDING)
        ->and($writeOff->attachments()->count())->toBe(1)
        ->and(LoanEntry::query()->where('kind', 'write_off')->count())->toBe(0)
        ->and(app(ApprovalInbox::class)->pendingActionableFor($this->w->owner)->pluck('approvable_id')->all())->toContain($writeOff->id);

    $card = app(ApprovalCards::class)->present($writeOff->approvalRequest);
    expect($card['type'])->toBe('Principal write-off')
        ->and($card['reason'])->toBe('Borrower died; estate has nothing')
        ->and(collect($card['details'])->firstWhere('label', 'Books')['value'])->toContain('6406');

    $this->actingAs($this->w->finance)->post(route('accounting.loans.write-offs.approve', $writeOff))->assertForbidden();
    $this->actingAs($this->w->owner)->post(route('accounting.loans.write-offs.approve', $writeOff))->assertSessionHas('success');

    $entry = LoanEntry::query()->where('kind', LoanEntryKind::WriteOff->value)->sole();

    expect($writeOff->fresh()->status)->toBe(LoanWriteOff::POSTED)
        ->and(($this->onHead)($entry, 'bad_debts'))->toBe(12_000_000)
        ->and(($this->onHead)($entry, 'loans_to_other_parties'))->toBe(-12_000_000)
        ->and($this->w->ledger('interest_receivable', $this->w->rahim))->toBe(0)
        ->and($this->loan->fresh()->status)->toBe(LoanStatus::WrittenOff);
});

it('writes off principal only on money the business lent', function () {
    $taken = $this->w->approved(['direction' => 'taken']);
    $this->w->advance($taken, '1000', '2026-01-01');

    expect(LoanWorld::refusal(fn () => ($this->ask)(['kind' => 'principal_write_off', 'amount' => '1000'], $taken)))->toContain('Only money the business lent is written off');
});

it('waives interest apart from principal: accrued interest reversed against 4920, the rest forgone on the schedule', function () {
    app(AccrueLoanInterest::class)->handle($this->loan->fresh(), '2026-01', $this->w->finance);

    $writeOff = ($this->ask)(['kind' => 'interest_waiver', 'amount' => '1223.01', 'forgo' => '500']);
    app(LoanWriteOffs::class)->approve($writeOff, $this->w->owner);

    $entry = LoanEntry::query()->where('kind', LoanEntryKind::Waiver->value)->sole();
    $position = LoanPosition::of($this->loan->fresh());

    expect(($this->onHead)($entry, 'interest_income'))->toBe(122_301)
        ->and(($this->onHead)($entry, 'interest_receivable'))->toBe(-122_301)
        ->and($position->accrued)->toBe(0)
        // Principal untouched by a waiver.
        ->and($position->principal)->toBe(12_000_000)
        ->and($this->w->ledger('loans_to_other_parties', $this->w->rahim))->toBe(12_000_000)
        // 1,223.01 settles February's installment interest; 500 is forgone from the next one.
        ->and($position->currentRows()[0]['interest_unpaid'])->toBe(0)
        ->and($position->currentRows()[1]['interest_paid'])->toBe(50_000)
        ->and($position->currentRows()[0]['principal_paid'])->toBe(0);
});

it('forgoes interest that was never accrued without any journal', function () {
    $writeOff = ($this->ask)(['kind' => 'interest_waiver', 'amount' => '0', 'forgo' => '1000']);
    app(LoanWriteOffs::class)->approve($writeOff, $this->w->owner);

    $entry = LoanEntry::query()->where('kind', LoanEntryKind::Waiver->value)->sole();

    expect($entry->journal_id)->toBeNull()
        ->and((int) LoanPosition::of($this->loan->fresh())->currentRows()[1]['interest_paid'])->toBe(100_000);
});

it('reverses interest payable on a loan taken when the lender waives it: Dr 2127, Cr 6478', function () {
    $taken = $this->w->approved(['direction' => 'taken']);
    $this->w->advance($taken, '120000', '2026-01-01');
    app(AccrueLoanInterest::class)->handle($taken->fresh(), '2026-01', $this->w->finance);

    app(LoanWriteOffs::class)->approve(($this->ask)(['kind' => 'interest_waiver', 'amount' => '1223.01'], $taken), $this->w->owner);
    $entry = LoanEntry::query()->where('loan_contract_id', $taken->id)->where('kind', 'waiver')->sole();

    expect(($this->onHead)($entry, 'interest_payable'))->toBe(122_301)
        ->and(($this->onHead)($entry, 'interest_expense'))->toBe(-122_301);
});

it('books nothing when the Owner turns a write-off down', function () {
    $writeOff = ($this->ask)(['kind' => 'principal_write_off', 'amount' => '1000']);

    $this->actingAs($this->w->owner)->post(route('accounting.loans.write-offs.reject', $writeOff), ['review_note' => 'Keep chasing'])->assertSessionHas('success');

    expect($writeOff->fresh()->status)->toBe(LoanWriteOff::REJECTED)
        ->and(LoanEntry::query()->where('kind', 'write_off')->count())->toBe(0)
        ->and($this->w->ledger('bad_debts'))->toBe(0);
});

it('restructures with the Owner\'s approval, keeping the old terms and schedule as history', function () {
    $this->w->repay($this->loan, ['date' => '2026-02-01', 'amount' => '10661.85']);
    $amendments = app(LoanAmendments::class);

    expect(LoanWorld::refusal(fn () => $amendments->request($this->loan->fresh(), [
        'effective_from' => '2026-01-20', 'annual_rate' => '10', 'repayment_type' => 'equal_installments', 'frequency' => 'monthly', 'maturity_date' => '2027-06-01', 'reason' => 'x',
    ], $this->w->finance)))->toContain('Money was recorded on this loan on 1 Feb 2026');

    $amendment = $amendments->request($this->loan->fresh(), [
        'effective_from' => '2026-03-15', 'annual_rate' => '10', 'repayment_type' => 'equal_installments', 'frequency' => 'monthly',
        'maturity_date' => '2027-06-15', 'reason' => 'Borrower asked for longer; agreed in the letter of 10 March',
    ], $this->w->finance);

    expect(LoanWorld::refusal(fn () => $amendments->request($this->loan->fresh(), ['effective_from' => '2026-03-16', 'repayment_type' => 'bullet', 'frequency' => 'at_maturity', 'maturity_date' => '2027-06-15', 'reason' => 'x'], $this->w->finance)))
        ->toContain('already has an amendment waiting')
        ->and(app(ApprovalCards::class)->present($amendment->approvalRequest)['type'])->toBe('Loan restructure');

    $this->actingAs($this->w->owner)->post(route('accounting.loans.amendments.approve', $amendment))->assertSessionHas('success');

    $loan = $this->loan->fresh();
    $position = LoanPosition::of($loan);
    $current = $position->currentRows();

    expect($amendment->fresh()->status)->toBe(LoanAmendment::APPLIED)
        ->and($loan->terms_version)->toBe(2)
        ->and((string) $loan->annual_rate)->toBe('10.0000')
        ->and($loan->maturity_date->toDateString())->toBe('2027-06-15')
        ->and($loan->termsVersions()->count())->toBe(2)
        // The old schedule stays: 12 rows of version 1, the first still paid.
        ->and(LoanScheduleRow::query()->where('loan_contract_id', $loan->id)->where('version', 1)->count())->toBe(12)
        ->and(collect($position->rows)->firstWhere('version', 1)['status'])->toBe('superseded')
        ->and(collect($position->rows)->firstWhere('version', 1)['principal_paid'])->toBe(943_884)
        // The new one covers exactly what is owed, and carries the unpaid March interest as interest.
        ->and(array_sum(array_column($current, 'principal_due')))->toBe(12_000_000 - 943_884)
        ->and($current[0]['due_date']->toDateString())->toBe('2026-04-15')
        ->and($current[0]['interest_due'])->toBeGreaterThan(101_775);
});

it('turns a restructure down without touching the terms', function () {
    $amendment = app(LoanAmendments::class)->request($this->loan->fresh(), [
        'effective_from' => '2026-03-15', 'annual_rate' => '10', 'repayment_type' => 'bullet', 'frequency' => 'at_maturity',
        'maturity_date' => '2027-06-15', 'reason' => 'Asked by the borrower',
    ], $this->w->finance);

    $this->actingAs($this->w->owner)->post(route('accounting.loans.amendments.reject', $amendment), ['review_note' => 'No'])->assertSessionHas('success');

    expect($amendment->fresh()->status)->toBe(LoanAmendment::REJECTED)
        ->and($this->loan->fresh()->terms_version)->toBe(1);
});
