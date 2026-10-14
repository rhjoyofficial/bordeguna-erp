<?php

use App\Modules\Accounting\Actions\Loans\LoanApprovals;
use App\Modules\Accounting\Actions\Loans\SaveLoanContract;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanScheduleRow;
use App\Modules\Accounting\Models\LoanTermsVersion;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use App\Support\RecordTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\LoanWorld;

/**
 * The Loan Register's agreement (loans plan v2, phase 2): what a loan may be,
 * which heads it sits on, the schedule its terms produce, and its way to the
 * Owner and back.
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('numbers a draft LN-yyyy-nnnn and schedules equal installments on the declining balance at actual/365', function () {
    $loan = $this->w->draft();
    $rows = LoanScheduleRow::query()->where('loan_contract_id', $loan->id)->orderBy('sequence')->get();

    expect($loan->loan_number)->toBe('LN-2026-0001')
        ->and($loan->status)->toBe(LoanStatus::Draft)
        ->and($rows)->toHaveCount(12)
        ->and($rows->first()->due_date->toDateString())->toBe('2026-02-01')
        ->and($rows->last()->due_date->toDateString())->toBe('2027-01-01')
        // The principal adds up to the paisa: the last row takes the rest.
        ->and((int) $rows->sum('principal_due_minor'))->toBe(12_000_000)
        // 120,000.00 x 12% x 31/365 = 1,223.0137 -> 1,223.01
        ->and($rows->first()->interest_due_minor)->toBe(122_301)
        // The level installment for 1% a month over 12: 10,661.85; its principal part is what the interest leaves.
        ->and($rows->first()->principal_due_minor)->toBe(1_066_185 - 122_301);

    // Each later row's interest is on the balance left after the rows before it.
    $balance = 12_000_000 - $rows[0]->principal_due_minor;
    $days = $rows[0]->due_date->diffInDays($rows[1]->due_date);
    expect($rows[1]->interest_due_minor)->toBe((int) round($balance * 0.12 * $days / 365));

    expect($this->w->draft()->loan_number)->toBe('LN-2026-0002');
});

it('schedules a bullet loan with interest each period and the principal at maturity, and grace months as interest only', function () {
    $bullet = $this->w->draft(['repayment_type' => 'bullet', 'frequency' => 'quarterly']);
    $rows = $bullet->scheduleRows;

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('principal_due_minor')->all())->toBe([0, 0, 0, 12_000_000])
        ->and($rows->every(fn ($row) => $row->interest_due_minor > 0))->toBeTrue();

    $graced = $this->w->draft(['grace_months' => 3]);
    $rows = $graced->scheduleRows;

    expect($rows->take(3)->pluck('principal_due_minor')->all())->toBe([0, 0, 0])
        ->and($rows->take(3)->every(fn ($row) => $row->interest_due_minor > 0))->toBeTrue()
        ->and((int) $rows->sum('principal_due_minor'))->toBe(12_000_000);

    $atMaturity = $this->w->draft(['repayment_type' => 'bullet', 'frequency' => 'at_maturity']);
    expect($atMaturity->scheduleRows)->toHaveCount(1)
        // 365 days at 12% on 120,000: 14,400.00 exactly.
        ->and($atMaturity->scheduleRows->first()->interest_due_minor)->toBe(1_440_000);
});

it('takes a custom schedule only when its principal adds up, working out interest left blank', function () {
    $rows = [
        ['due_date' => '2026-07-01', 'principal' => '60000', 'interest' => ''],
        ['due_date' => '2027-01-01', 'principal' => '50000', 'interest' => '100'],
    ];

    expect(LoanWorld::refusal(fn () => $this->w->draft(['repayment_type' => 'custom', 'custom_rows' => $rows])))
        ->toContain('differs from the loan by 10,000.00');

    $rows[1]['principal'] = '60000';
    $loan = $this->w->draft(['repayment_type' => 'custom', 'custom_rows' => $rows]);

    expect($loan->scheduleRows->pluck('principal_due_minor')->all())->toBe([6_000_000, 6_000_000])
        // 181 days at 12% on 120,000: 7,140.82, worked out because it was left blank.
        ->and($loan->scheduleRows->first()->interest_due_minor)->toBe((int) round(12_000_000 * 0.12 * 181 / 365))
        ->and($loan->scheduleRows->last()->interest_due_minor)->toBe(10_000);
});

it('allows an interest-free loan only with its reason written down', function () {
    expect(LoanWorld::refusal(fn () => $this->w->draft(['annual_rate' => '0'])))->toContain('needs its reason written down');

    $loan = $this->w->draft(['annual_rate' => '0', 'interest_free_reason' => 'Owner decided: help to a long-standing supplier']);

    expect($loan->interest_free_reason)->toBe('Owner decided: help to a long-standing supplier')
        ->and((int) $loan->scheduleRows->sum('interest_due_minor'))->toBe(0);
});

it('puts the principal on the head the direction, the term and the counterparty call for', function () {
    $head = fn (LoanContract $loan) => $loan->principalAccount->system_slug;

    expect($head($this->w->draft()))->toBe('loans_to_other_parties')
        ->and($head($this->w->draft(['counterparty_id' => $this->w->tycoon->id])))->toBe('loans_to_related_parties');

    $long = $this->w->draft(['maturity_date' => '2028-01-01']);
    expect($head($long))->toBe('long_term_loans_given')
        ->and($long->currentPortionAccount->system_slug)->toBe('current_portion_of_loans_given')
        ->and($long->interestAccrualAccount->system_slug)->toBe('interest_receivable')
        ->and($long->interestPlAccount->system_slug)->toBe('interest_income');

    $bank = $this->w->draft(['direction' => 'taken', 'counterparty_id' => $this->w->padma->id, 'maturity_date' => '2029-01-01']);
    expect($head($bank))->toBe('bank_loans')
        ->and($bank->currentPortionAccount->system_slug)->toBe('current_portion_of_long_term_loans')
        ->and($bank->interestAccrualAccount->system_slug)->toBe('interest_payable')
        ->and($bank->interestPlAccount->system_slug)->toBe('interest_expense');

    expect($head($this->w->draft(['direction' => 'taken', 'counterparty_id' => $this->w->rahim->id])))->toBe('short_term_loans_payable')
        ->and($head($this->w->draft(['direction' => 'taken', 'counterparty_id' => $this->w->rahim->id, 'maturity_date' => '2028-06-30'])))->toBe('loans_from_other_parties');

    // A head that does not fit is refused with the one that does.
    expect(LoanWorld::refusal(fn () => $this->w->draft(['principal_head' => 'long_term_loans_given'])))
        ->toContain('is due within 12 months, so its principal goes on 11751 - Loans to Other Parties');
});

it('takes Loans from Owners with a warning, never a block', function () {
    $save = app(SaveLoanContract::class);
    $loan = $save->handle([
        'direction' => 'taken', 'counterparty_id' => $this->w->rahim->id, 'principal' => '500000',
        'start_date' => '2026-01-01', 'maturity_date' => '2029-01-01', 'annual_rate' => '0', 'interest_free_reason' => 'Owner loan agreed with the accountant',
        'repayment_type' => 'bullet', 'frequency' => 'at_maturity', 'principal_head' => 'owner_loans',
    ], $this->w->finance);

    expect($loan->principalAccount->system_slug)->toBe('owner_loans')
        ->and($save->warnings)->toHaveCount(1)
        ->and($save->warnings[0])->toContain("Owner's Capital (3100)");
});

it('refuses one of our own businesses, and a deactivated counterparty, as the other side of a loan', function () {
    // Made around the screen (which refuses it), as an old row might be.
    $laladia = Counterparty::create(['legal_name' => 'LaLaDia', 'kind' => 'company']);

    expect(LoanWorld::refusal(fn () => $this->w->draft(['counterparty_id' => $laladia->id])))
        ->toContain('Inter-business transfers');

    $this->w->padma->update(['is_active' => false]);
    expect(LoanWorld::refusal(fn () => $this->w->draft(['counterparty_id' => $this->w->padma->id])))->toContain('is deactivated');
});

it('reaches the Owner through the approval workflow, and nobody else', function () {
    $loan = $this->w->draft();
    $approvals = app(LoanApprovals::class);
    $approvals->submit($loan, $this->w->finance);
    $loan->refresh();

    $inbox = app(ApprovalInbox::class);
    $ids = fn ($user) => $inbox->pendingActionableFor($user)->pluck('approvable_id')->all();

    expect($loan->status)->toBe(LoanStatus::PendingApproval)
        ->and($loan->approvalRequest->workflow->key)->toBe('loan_contract')
        ->and($ids($this->w->owner))->toContain($loan->id)
        ->and($ids($this->w->finance))->not->toContain($loan->id)
        ->and($ids($this->w->manager))->not->toContain($loan->id);

    // The card states the facts and carries working links.
    $card = app(ApprovalCards::class)->present($loan->approvalRequest);
    expect($card['type'])->toBe('Loan agreement')
        ->and($card['approve_url'])->toBe(route('accounting.loans.approve', $loan))
        ->and(collect($card['details'])->pluck('label')->all())->toContain('Principal', 'Interest', 'Term', 'Books');

    // Finance cannot approve; the Owner can, over HTTP, like the inbox does.
    $this->actingAs($this->w->finance)->post(route('accounting.loans.approve', $loan))->assertForbidden();
    $this->actingAs($this->w->owner)->post(route('accounting.loans.approve', $loan))->assertRedirect();

    $loan->refresh();
    $version = LoanTermsVersion::query()->where('loan_contract_id', $loan->id)->sole();

    expect($loan->status)->toBe(LoanStatus::Approved)
        ->and($version->version)->toBe(1)
        ->and($version->effective_from->toDateString())->toBe('2026-01-01')
        ->and($version->terms['annual_rate'])->toBe('12');
});

it('asks the Owner for a reason before approving a loan they drafted themselves', function () {
    $loan = $this->w->draft([], $this->w->owner);
    $approvals = app(LoanApprovals::class);
    $approvals->submit($loan, $this->w->owner);

    expect(LoanWorld::refusal(fn () => $approvals->approve($loan->fresh(), $this->w->owner)))->toContain('write a short reason');

    $approvals->approve($loan->fresh(), $this->w->owner, 'Agreed with the borrower in person.');

    expect($loan->fresh()->status)->toBe(LoanStatus::Approved)
        ->and($loan->approvalRequest->decisions()->first()->self_approved)->toBeTrue();
});

it('sends a loan back as a draft with the note, and takes it again once corrected', function () {
    $loan = $this->w->draft();
    $approvals = app(LoanApprovals::class);
    $approvals->submit($loan, $this->w->finance);

    $this->actingAs($this->w->owner)->post(route('accounting.loans.send-back', $loan), ['review_note' => 'The rate is 11%, not 12%.'])->assertRedirect();
    expect($loan->fresh()->status)->toBe(LoanStatus::Draft);

    app(SaveLoanContract::class)->handle([
        'direction' => 'given', 'counterparty_id' => $this->w->rahim->id, 'principal' => '120000', 'start_date' => '2026-01-01',
        'maturity_date' => '2027-01-01', 'annual_rate' => '11', 'repayment_type' => 'equal_installments', 'frequency' => 'monthly',
    ], $this->w->finance, $loan->fresh());

    $approvals->submit($loan->fresh(), $this->w->finance);
    expect($loan->fresh()->status)->toBe(LoanStatus::PendingApproval)
        ->and((string) $loan->fresh()->annual_rate)->toBe('11.0000');

    // While it waits, it cannot be edited or cancelled.
    expect(LoanWorld::refusal(fn () => app(SaveLoanContract::class)->handle([], $this->w->finance, $loan->fresh())))
        ->toContain('Only a draft is edited')
        ->and(LoanWorld::refusal(fn () => $approvals->cancel($loan->fresh(), $this->w->finance, 'No longer needed')))->toContain('Ask them to send it back first');
});

it('cancels a draft or an approved loan with no money, with a reason', function () {
    $draft = $this->w->draft();
    $approvals = app(LoanApprovals::class);

    expect(LoanWorld::refusal(fn () => $approvals->cancel($draft, $this->w->finance, ' ')))->toContain('Say why');

    $approvals->cancel($draft, $this->w->finance, 'Borrower withdrew');
    expect($draft->fresh()->status)->toBe(LoanStatus::Cancelled)->and($draft->fresh()->cancel_reason)->toBe('Borrower withdrew');

    $approved = $this->w->approved();
    $approvals->cancel($approved, $this->w->finance, 'Never paid out');
    expect($approved->fresh()->status)->toBe(LoanStatus::Cancelled);

    $active = $this->w->approved();
    $this->w->advance($active, '120000', '2026-01-01');
    expect(LoanWorld::refusal(fn () => $approvals->cancel($active->fresh(), $this->w->finance, 'Oops')))->toContain('cannot be cancelled');
});

it('refuses to send a loan for approval when no workflow would carry it to anybody', function () {
    ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)->where('key', 'loan_contract')->update(['is_active' => false]);

    expect(LoanWorld::refusal(fn () => app(LoanApprovals::class)->submit($this->w->draft(), $this->w->finance)))
        ->toContain('Nobody is set up to approve loan agreements');
});

it('lets Owner, Finance and Manager read the register, Owner and Finance keep it, and nobody else', function () {
    $loan = $this->w->approved();
    $staff = $this->w->member('Staff');

    foreach ([$this->w->owner, $this->w->finance, $this->w->manager] as $reader) {
        $this->actingAs($reader)->get('/accounting/loans')->assertOk();
        $this->actingAs($reader)->get("/accounting/loans/{$loan->id}")->assertOk();
        $this->actingAs($reader)->get("/accounting/loans/{$loan->id}/statement")->assertOk();
        $this->actingAs($reader)->get('/accounting/loans/reports')->assertOk();
    }

    $this->actingAs($staff)->get('/accounting/loans')->assertForbidden();
    $this->actingAs($this->w->manager)->get('/accounting/loans/new')->assertForbidden();
    $this->actingAs($this->w->manager)->post("/accounting/loans/{$loan->id}/advance", [])->assertForbidden();

    $this->actingAs($this->w->finance)->get('/accounting/loans/new')->assertOk();
    $this->actingAs($this->w->finance)->post('/accounting/loans', [
        'direction' => 'taken', 'counterparty_id' => $this->w->padma->id, 'principal' => '1000000', 'start_date' => '2026-03-01',
        'maturity_date' => '2029-03-01', 'annual_rate' => '14.5', 'repayment_type' => 'equal_installments', 'frequency' => 'monthly',
    ])->assertRedirect();

    expect(LoanContract::query()->where('counterparty_id', $this->w->padma->id)->sole()->principalAccount->code)->toBe('2210');
});

it('logs every change to a loan in its history', function () {
    $loan = $this->w->approved();

    $what = collect(RecordTimeline::for($loan))->pluck('what')->implode(' | ');

    expect($what)->toContain('Sent LN-2026-0001 to the Owner for approval')
        ->and($what)->toContain('Approved LN-2026-0001');
});

it('keeps each loan in its own business', function () {
    $loan = $this->w->approved();
    $lalaOwner = $this->w->member('Owner', $this->w->laladia);
    (new AccountingPermissionsSeeder)->run();

    $this->actingAs($lalaOwner)->withSession(['current_business_id' => $this->w->laladia->id])
        ->get("/accounting/loans/{$loan->id}")->assertNotFound();
});

it('puts Loans in the menu and keeps its pages reachable', function () {
    expect(file_get_contents(resource_path('js/components/app-sidebar.tsx')))->toContain('/accounting/loans');
});

it('refuses a non-draft edit and an unknown counterparty with a sentence', function () {
    expect(LoanWorld::refusal(fn () => $this->w->draft(['counterparty_id' => 999999])))->toContain('Choose the counterparty');

    $this->expectException(ValidationException::class);
    $this->w->draft(['maturity_date' => '2025-12-31']);
});
