<?php

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionRequestStatus;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use App\Support\Dashboards\Widgets\FinanceApprovalsWidget;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;

/**
 * The Tk 20,000 second person (PHASE-0-CONTRACT v2, 1.8; 3.7 tests 24 to 29;
 * ChatGPT's 43 and 45). Basis = max(original, corrected); the Owner is never
 * exempt; a request is re-checked from scratch when it is approved.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('24. 30,000 to 25,000 by B (maker A): a request, nothing posts, B cannot approve, A (the maker), the Owner or C can; approval posts with approved_by', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $result = $this->w->correct($voucher, $this->w->financeB, ['amount' => '25000']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approval_route)->toBe(ApprovalRoute::SecondPerson)
        ->and($result->request->amount_basis_minor)->toBe(3_000_000)
        ->and($result->message)->toBe('Sent for approval. Nothing changes until a second person approves it.')
        ->and(Journal::count())->toBe(1)
        ->and($voucher->fresh()->isReversed())->toBeFalse();

    $approval = $result->request->approvalRequest;
    expect($approval->canBeActionedBy($this->w->financeB))->toBeFalse()
        ->and($approval->canBeActionedBy($this->w->financeA))->toBeTrue() // the original's maker may (Joy, 19 Oct 2026)
        ->and($approval->canBeActionedBy($this->w->owner))->toBeTrue()
        ->and($approval->canBeActionedBy($this->w->financeC))->toBeTrue()
        ->and($approval->canBeActionedBy($this->w->manager))->toBeFalse();

    // It is in C's inbox, on the Finance approvals widget, with a card.
    $pending = app(ApprovalInbox::class)->pendingActionableFor($this->w->financeC);
    expect($pending->pluck('id')->all())->toContain($approval->id)
        ->and(in_array('CorrectionRequest', FinanceApprovalsWidget::FINANCE_TYPES, true))->toBeTrue();

    $card = app(ApprovalCards::class)->present($pending->firstWhere('id', $approval->id));
    expect($card['approve_url'])->toBe(route('corrections.requests.approve', $result->request))
        ->and($card['summary'])->toContain('Tk 30,000 to Tk 25,000')
        ->and($card['summary'])->toContain('asked by Shuborna');

    $this->actingAs($this->w->financeB)->post(route('corrections.requests.approve', $result->request))->assertForbidden();
    // A (the maker) may approve now (Joy, 19 Oct 2026); C approves here so the link records C.
    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $result->request))->assertSessionHas('success');

    $link = CorrectionLink::sole();
    expect($link->approved_by)->toBe($this->w->financeC->id)
        ->and($link->actor_id)->toBe($this->w->financeB->id)
        ->and($link->correction_request_id)->toBe($result->request->id)
        ->and($result->request->fresh()->status)->toBe(CorrectionRequestStatus::Executed)
        ->and($result->request->fresh()->execution_summary['effects'])->not->toBeEmpty()
        ->and($this->w->balance('11201'))->toBe(2_500_000);
});

it('25. the Pathao case: 102,340.00 corrected to 1,023.40 needs a second person (basis = max)', function () {
    $voucher = $this->w->record('102340', '2026-10-12', by: $this->w->financeA);
    $result = $this->w->correct($voucher, $this->w->financeB, ['amount' => '1023.40']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->amount_basis_minor)->toBe(10_234_000)
        ->and($result->request->successor_minor)->toBe(102_340);
});

it('26. 15,000 to 19,000 executes at once (below the limit)', function () {
    $voucher = $this->w->record('15000', '2026-10-15', by: $this->w->financeA);

    expect($this->w->correct($voucher, $this->w->financeB, ['amount' => '19000'])->outcome)->toBe(CorrectionOutcome::Executed);
});

it('27. a limit of 0 in the Finance policy switches the rule off', function () {
    FinancePolicy::query()->update(['payment_second_approval_above_minor' => 0]);
    $voucher = $this->w->record('500000', '2026-10-15', by: $this->w->financeA);

    expect($this->w->correct($voucher, $this->w->financeB, ['amount' => '450000'])->outcome)->toBe(CorrectionOutcome::Executed);
});

it('28/45. a request approved after the voucher changed fails as failed_stale, posts nothing, and tells the requester', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $request = $this->w->correct($voucher, $this->w->financeB, ['amount' => '25000'])->request;

    // Hours later (the preview token long expired), the voucher's money line
    // is matched on the bank statement.
    Carbon::setTestNow(Carbon::now()->addHours(5));
    $this->w->matchOnBankStatement($voucher);

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $request))
        ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Approved, but nothing was posted:'));

    $request->refresh();
    expect($request->status)->toBe(CorrectionRequestStatus::FailedStale)
        ->and($request->failure_message)->toContain('changed while the request waited')
        ->and($request->pending_key)->toBeNull()
        ->and($request->approvalRequest->decisions()->count())->toBe(1)
        ->and(CorrectionLink::count())->toBe(0)
        ->and(Journal::count())->toBe(1);
});

it('45b. a request approved after its preview expired still executes, after full revalidation', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $request = $this->w->correct($voucher, $this->w->financeB, ['amount' => '25000'])->request;

    Carbon::setTestNow(Carbon::now()->addDays(1));

    $this->actingAs($this->w->owner)->post(route('corrections.requests.approve', $request))->assertSessionHas('success');

    expect($request->fresh()->status)->toBe(CorrectionRequestStatus::Executed)
        ->and(CorrectionLink::sole()->approved_by)->toBe($this->w->owner->id);
});

it('29/43. the Owner\'s own correction above the limit needs a second person too', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $result = $this->w->correct($voucher, $this->w->owner, ['amount' => '35000']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approvalRequest->canBeActionedBy($this->w->owner))->toBeFalse()
        ->and($result->request->approvalRequest->canBeActionedBy($this->w->financeC))->toBeTrue();
});

it('43b. a super admin is never their own second person', function () {
    $joy = User::factory()->create(['name' => 'Joy', 'is_super_admin' => true]);
    $joy->businesses()->attach($this->w->business->id);

    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $result = $this->w->correct($voucher, $joy, ['amount' => '25000']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approvalRequest->canBeActionedBy($joy))->toBeFalse();
});

it('a second request for the same voucher is refused while one waits; reject and withdraw close it with nothing posted', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $request = $this->w->correct($voucher, $this->w->financeB, ['amount' => '25000'])->request;

    expect(fn () => $this->w->correct($voucher->fresh(), $this->w->financeC, ['amount' => '26000']))
        ->toThrow(CorrectionBlockedException::class, 'is already waiting for approval');

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.reject', $request), [])->assertSessionHasErrors('review_note');
    $this->actingAs($this->w->financeC)->post(route('corrections.requests.reject', $request), ['review_note' => 'The bank advice says 30,000.'])->assertSessionHas('success');

    expect($request->fresh()->status)->toBe(CorrectionRequestStatus::Rejected);

    $again = $this->w->correct($voucher->fresh(), $this->w->financeB, ['amount' => '25000'])->request;

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.withdraw', $again), ['reason_text' => 'Not mine to withdraw'])->assertForbidden();
    $this->actingAs($this->w->financeB)->post(route('corrections.requests.withdraw', $again), ['reason_text' => 'Asked the wrong amount'])->assertSessionHas('success');

    expect($again->fresh()->status)->toBe(CorrectionRequestStatus::Withdrawn)
        ->and($again->fresh()->approvalRequest->status->value)->toBe('rejected')
        ->and(CorrectionLink::count())->toBe(0)
        ->and(IncomeVoucher::count())->toBe(1);
});

it('a requester who lost the correct permission while the request waited gets nothing posted', function () {
    $voucher = $this->w->record('30000', '2026-10-15', by: $this->w->financeA);
    $request = $this->w->correct($voucher, $this->w->financeB, ['amount' => '25000'])->request;

    $this->w->financeB->syncRoles(['Manager']);

    $this->actingAs($this->w->owner)->post(route('corrections.requests.approve', $request))->assertSessionHas('error');

    expect($request->fresh()->status)->toBe(CorrectionRequestStatus::FailedStale)
        ->and(CorrectionLink::count())->toBe(0);
});
