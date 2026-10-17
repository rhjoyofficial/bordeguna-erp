<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\ClosedPeriodExceptions;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionRequestStatus;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\ClosedPeriodPassException;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CorrectionWorld;

/**
 * The closed month (PHASE-0-CONTRACT v2, 1.10; 3.7 tests 17 to 23; ChatGPT's
 * 42, 44, 50, 52). Section 9 answer 4: posted on the ORIGINAL date as a logged
 * one-document exception, by the Owner, with NO general unlock.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->september = $this->w->record('5000', '2026-09-15');
    $this->big = $this->w->record('30000', '2026-09-10', by: $this->w->financeA);
    $this->w->closeThrough('2026-09-30');
    $this->policy = app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE);
});

afterEach(fn () => Carbon::setTestNow());

/** Grants a pass inside a transaction the caller controls. */
function grantPass(CorrectionWorld $w, IncomeVoucher $voucher, array $dates = ['2026-09-15'], int $max = 1, string $action = 'reverse')
{
    return app(ClosedPeriodExceptions::class)->grant(
        $voucher, $voucher->voucher_number, $action, IncomeVoucher::class, $dates, $max, $w->owner, $w->owner, new ActionReason(null, 'Test exception'),
    );
}

it('17. Finance on a closed-month voucher: Correct is blocked with the Owner sentence; Ask the Owner sends a request only the Owner can act on', function () {
    $availability = $this->policy->availability($this->september, $this->w->financeB, DocumentAction::Correct);

    expect($availability->allowed)->toBeFalse()
        ->and($availability->isRequestable())->toBeTrue()
        ->and($availability->blockers[0]->message)->toBe('September 2026 is closed. Only the Owner can correct a closed month.');

    $result = $this->w->correct($this->september, $this->w->financeB, ['amount' => '4500']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approval_route)->toBe(ApprovalRoute::OwnerClosedPeriod)
        ->and($result->request->workflow_key)->toBe('correction_closed_period')
        ->and(CorrectionLink::count())->toBe(0);

    $approval = $result->request->approvalRequest;
    expect($approval->canBeActionedBy($this->w->financeC))->toBeFalse()
        ->and($approval->canBeActionedBy($this->w->financeB))->toBeFalse()
        ->and($approval->canBeActionedBy($this->w->owner))->toBeTrue();

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $result->request))->assertForbidden();
    $response = $this->actingAs($this->w->owner)->post(route('corrections.requests.approve', $result->request));
    $response->assertRedirect();
    expect(session('error'))->toBeNull();
    $response->assertSessionHas('success');

    $link = CorrectionLink::sole();
    $exception = ClosedPeriodException::sole();

    expect($result->request->fresh()->status)->toBe(CorrectionRequestStatus::Executed)
        ->and($link->closed_period_exception)->toBeTrue()
        ->and($link->approved_by)->toBe($this->w->owner->id)
        ->and($link->actor_id)->toBe($this->w->financeB->id)
        ->and($exception->granted_by)->toBe($this->w->owner->id)
        ->and($exception->status)->toBe('used')
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

it('18. the Owner corrects a closed-month voucher: both journals on September dates, the month never unlocked, the exception used with 2 journals', function () {
    $unlockedSeen = [];
    Journal::created(function () use (&$unlockedSeen) {
        $unlockedSeen[] = (bool) AccountingPeriod::current()?->is_unlocked;
    });

    $result = $this->w->correct($this->september, $this->w->owner, ['amount' => '4500', 'received_on' => '2026-09-14']);

    $exception = ClosedPeriodException::sole();
    $reversal = $result->reversalJournal;
    $successorJournal = Journal::find($result->successor->journal_id);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($reversal->journal_date->toDateString())->toBe('2026-09-15')
        ->and($successorJournal->journal_date->toDateString())->toBe('2026-09-14')
        ->and($reversal->period_exception_id)->toBe($exception->id)
        ->and($successorJournal->period_exception_id)->toBe($exception->id)
        ->and([$reversal->period_exception_slot, $successorJournal->period_exception_slot])->toBe([1, 2])
        ->and($exception->status)->toBe('used')
        ->and($exception->journals_posted)->toBe(2)
        ->and($exception->max_journals)->toBe(2)
        ->and($exception->dates)->toBe(['2026-09-15', '2026-09-14'])
        ->and($exception->successor_id)->toBe($result->successor->id)
        ->and($exception->correction_link_id)->toBe($result->link->id)
        ->and($result->link->date_rule)->toBe('closed_period_exception')
        ->and($unlockedSeen)->not->toBeEmpty()
        ->and(in_array(true, $unlockedSeen, true))->toBeFalse()
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

it('19. while the pass is in use, any other posting into the closed month in the same request is refused', function () {
    $refused = null;

    Journal::created(function (Journal $journal) use (&$refused) {
        if ($journal->period_exception_id === null || $refused !== null) {
            return;
        }

        try {
            app(PostJournal::class)->handle(new JournalDraft(
                date: Carbon::parse('2026-09-10'),
                lines: [
                    JournalLineDraft::debit(Account::bySlug('cash_at_bank')->id, Money::ofTaka('10'), 'x', null, $this->w->bank),
                    JournalLineDraft::credit($this->w->scrap->id, Money::ofTaka('10'), 'x'),
                ],
                memo: 'A listener trying its luck',
            ), $this->w->owner);
            $refused = false;
        } catch (PeriodClosedException) {
            $refused = true;
        }
    });

    $this->w->reverse($this->september, $this->w->owner);

    expect($refused)->toBeTrue()
        ->and(Journal::where('memo', 'A listener trying its luck')->count())->toBe(0);
});

it('20/42. a pass cannot post another document, a third journal, an uncovered date, another business, another action, or after its transaction rolled back', function () {
    $other = IncomeVoucher::query()->create([
        'business_id' => $this->w->business->id, 'voucher_number' => 'INC-X', 'received_on' => '2026-09-20', 'money_account_id' => $this->w->bank->id,
        'total' => '1.0000', 'created_by' => $this->w->owner->id,
    ]);
    $exceptions = app(ClosedPeriodExceptions::class);

    DB::transaction(function () use ($exceptions, $other) {
        $pass = grantPass($this->w, $this->september);

        expect(fn () => $exceptions->consume($pass, $other, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class, 'granted for a different document')
            ->and(fn () => $exceptions->consume($pass, $this->september, Carbon::parse('2026-09-16')))->toThrow(ClosedPeriodPassException::class, 'does not cover')
            ->and($pass->covers(Carbon::parse('2026-09-16'), $this->w->business->id))->toBeFalse()
            // A reverse pass never lets a successor post.
            ->and(fn () => $exceptions->consume($pass, $other, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class);

        // Another business.
        TenantScope::enter($this->w->other->id);
        expect(fn () => $exceptions->consume($pass, $this->september, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class, 'another business');
        TenantScope::enter($this->w->business->id);

        // One journal allowed. The journals under the row are COUNTED (not the
        // counter trusted), so once one journal points at it, a second is refused.
        expect($exceptions->consume($pass, $this->september, Carbon::parse('2026-09-15')))->toBe(1);
        DB::table('journals')->insert([
            'business_id' => $this->w->business->id, 'journal_number' => 'JV-SLOT-1', 'journal_type' => 'manual',
            'journal_date' => '2026-09-15', 'memo' => 'slot 1', 'posted_by' => $this->w->owner->id, 'posted_at' => now(),
            'period_exception_id' => $pass->exceptionId, 'period_exception_slot' => 1, 'created_at' => now(),
        ]);
        DB::table('closed_period_exceptions')->where('id', $pass->exceptionId)->update(['journals_posted' => 0]);

        expect(fn () => $exceptions->consume($pass, $this->september, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class, 'every journal it allows');

        // ⚠ And the database refuses a second journal in the same slot.
        expect(fn () => DB::table('journals')->insert([
            'business_id' => $this->w->business->id, 'journal_number' => 'JV-SLOT-1B', 'journal_type' => 'manual',
            'journal_date' => '2026-09-15', 'memo' => 'slot 1 again', 'posted_by' => $this->w->owner->id, 'posted_at' => now(),
            'period_exception_id' => $pass->exceptionId, 'period_exception_slot' => 1, 'created_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    // After its transaction rolled back, a pass is useless: the row is gone.
    $kept = null;

    try {
        DB::transaction(function () use (&$kept) {
            $kept = grantPass($this->w, $this->september);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(ClosedPeriodException::query()->whereKey($kept->exceptionId)->exists())->toBeFalse();

    DB::transaction(function () use ($exceptions, $kept) {
        expect(fn () => $exceptions->consume($kept, $this->september, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class);
    });

    // A forged pass (right id, wrong secret) is refused.
    DB::transaction(function () use ($exceptions) {
        $real = grantPass($this->w, $this->september);
        $forged = new ClosedPeriodPass($real->exceptionId, $real->businessId, $real->originalType, $real->originalId, 'reverse', null, $real->dates, 5, str_repeat('0', 64));

        expect(fn () => $exceptions->consume($forged, $this->september, Carbon::parse('2026-09-15')))->toThrow(ClosedPeriodPassException::class, 'does not match');
    });

    // (Granting outside a transaction is refused by grant() itself; that guard
    // cannot be shown here, because RefreshDatabase runs every test inside one.)
});

it('20b. a used exception refuses a third journal and any reuse', function () {
    $result = $this->w->reverse($this->september, $this->w->owner);
    $exception = ClosedPeriodException::sole();

    $pass = new ClosedPeriodPass($exception->id, $this->w->business->id, $exception->original_type, $exception->original_id, 'reverse', null, $exception->dates, 1, 'unknown');

    DB::transaction(function () use ($pass) {
        expect(fn () => app(ClosedPeriodExceptions::class)->consume($pass, $this->september, Carbon::parse('2026-09-15')))
            ->toThrow(ClosedPeriodPassException::class, 'already used');
    });

    expect($result->link->closed_period_exception)->toBeTrue();
});

it('21. a Finance user handed accounting.correct.closed_period in Settings still cannot get a pass', function () {
    $this->w->financeB->givePermissionTo('accounting.correct.closed_period');

    $availability = $this->policy->availability($this->september, $this->w->financeB, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and($availability->blockers[0]->code)->toBe('period_closed_not_owner');

    DB::transaction(function () {
        expect(fn () => app(ClosedPeriodExceptions::class)->grant(
            $this->september, $this->september->voucher_number, 'reverse', null, ['2026-09-15'], 1, $this->w->financeB, $this->w->financeB, new ActionReason(null, 'Trying'),
        ))->toThrow(ClosedPeriodPassException::class, 'Only the Owner can correct a closed month.');
    });

    // And the Owner role without the permission is not enough either.
    Role::where('name', 'Owner')->where('business_id', $this->w->business->id)->first()->revokePermissionTo('accounting.correct.closed_period');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::transaction(function () {
        expect(fn () => grantPass($this->w, $this->september))->toThrow(ClosedPeriodPassException::class);
    });
});

it('22. a closed-month correction keeps the successor in its own month: another closed month and an open month are both refused', function () {
    $into = fn (string $date) => $this->w->preview(
        $this->w->input($this->september, DocumentAction::Correct, $this->w->replacement($this->september, ['amount' => '4500', 'received_on' => $date])),
        $this->w->owner,
    );

    foreach (['2026-08-20', '2026-10-05'] as $date) {
        $preview = $into($date);
        expect($preview->previewToken)->toBeNull()
            ->and(collect($preview->blockers)->pluck('code')->all())->toContain('closed_month_move')
            ->and(collect($preview->blockers)->pluck('message')->first())->toBe('A closed-month correction keeps the record in September 2026. To move it to another month, reverse it and record a new one there.');
    }

    // From an open month, a successor moved INTO a closed month is refused too.
    $october = $this->w->record('800', '2026-10-10');
    $preview = $this->w->preview($this->w->input($october, DocumentAction::Correct, $this->w->replacement($october, ['received_on' => '2026-09-20'])), $this->w->owner);
    expect(collect($preview->blockers)->pluck('message')->all())->toContain('A correction cannot move the record into another closed month.');
});

it('23/52. accounting:verify-ledger passes after an exception and finds a mis-scoped, overused or unclosed one', function () {
    $this->w->correct($this->september, $this->w->owner, ['amount' => '4500']);

    expect(Artisan::call('accounting:verify-ledger'))->toBe(0);

    $exception = ClosedPeriodException::sole();
    $reversal = Journal::whereNotNull('reverses_journal_id')->sole();

    // Mis-scoped: a forged journal on a date the exception does not cover.
    // (Written raw on purpose: the test plays a broken writer.)
    DB::table('journals')->insert([
        'business_id' => $this->w->business->id, 'journal_number' => 'JV-FORGED-1', 'journal_type' => $reversal->journal_type->value,
        'journal_date' => '2026-09-01', 'memo' => 'forged', 'posted_by' => $this->w->owner->id, 'posted_at' => now(),
        'source_type' => $exception->original_type, 'source_id' => $exception->original_id,
        'period_exception_id' => $exception->id, 'period_exception_slot' => 9, 'created_at' => now(),
    ]);

    Artisan::call('accounting:verify-ledger');
    $output = Artisan::output();

    expect($output)->toContain('does not cover')
        ->and($output)->toContain('says 2 journal(s), but 3 point at it')
        ->and($output)->toContain('more than its 2');

    // Never closed: a granted row outside any transaction is a problem.
    DB::table('closed_period_exceptions')->where('id', $exception->id)->update(['status' => 'granted']);
    Artisan::call('accounting:verify-ledger');
    expect(Artisan::output())->toContain('was never closed');
});

it('44. the Owner above the limit in a closed month: no deadlock, no bypass; a different eligible person approves', function () {
    $result = $this->w->correct($this->big, $this->w->owner, ['amount' => '25000']);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approval_route)->toBe(ApprovalRoute::SecondPerson)
        ->and($result->request->owner_authorised_by)->toBe($this->w->owner->id);

    $approval = $result->request->approvalRequest;
    expect($approval->canBeActionedBy($this->w->owner))->toBeFalse()      // the requester
        ->and($approval->canBeActionedBy($this->w->financeA))->toBeFalse() // the original's maker
        ->and($approval->canBeActionedBy($this->w->financeC))->toBeTrue();

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $result->request))->assertSessionHas('success');

    $exception = ClosedPeriodException::sole();
    expect($exception->granted_by)->toBe($this->w->owner->id)
        ->and($exception->executed_by)->toBe($this->w->financeC->id)
        ->and(CorrectionLink::sole()->approved_by)->toBe($this->w->financeC->id)
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

it('44b. with nobody else eligible, the Owner is refused with the plain sentence, never waved through', function () {
    $big = $this->big;

    foreach ([$this->w->financeA, $this->w->financeB, $this->w->financeC] as $finance) {
        $finance->syncRoles([]);
    }

    $preview = $this->w->preview($this->w->input($big, DocumentAction::Correct, $this->w->replacement($big, ['amount' => '25000'])), $this->w->owner);

    expect($preview->previewToken)->toBeNull()
        ->and(collect($preview->blockers)->pluck('message')->all())->toContain('This needs a second person with approval rights, and nobody else here has them.');
});

it('50. a rollback leaves no usable pass, no link and no used number', function () {
    $input = $this->w->input($this->september, DocumentAction::Correct, $this->w->replacement($this->september, ['account_id' => Account::bySlug('sales_revenue')->id]));

    expect(fn () => $this->w->submit($input, $this->w->owner))->toThrow(ValidationException::class);

    expect(ClosedPeriodException::count())->toBe(0)
        ->and(CorrectionLink::count())->toBe(0)
        ->and(Journal::whereNotNull('period_exception_id')->count())->toBe(0)
        ->and($this->september->fresh()->isReversed())->toBeFalse()
        ->and($this->w->record('10', '2026-10-15')->voucher_number)->toBe('INC-2026-27-0003'); // 0001 and 0002 were recorded before the close
});

it('the Today choice is never offered in a closed month', function () {
    $preview = $this->w->preview($this->w->input($this->september, DocumentAction::Reverse, choice: ReverseDateChoice::Today), $this->w->owner);

    expect(collect($preview->blockers)->pluck('code')->all())->toContain('today_closed_month');
});
