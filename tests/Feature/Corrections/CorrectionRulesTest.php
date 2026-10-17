<?php

use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\OriginalDatePolicy;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Accounting\Corrections\IncomeVoucherDuplicateRule;
use App\Modules\Foundation\Models\Reason;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;

/**
 * The rules that read the database: the date policy branch by branch (1.9),
 * the ack token (1.11), the reason display (1.6), the chain walks (1.7).
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->policy = new OriginalDatePolicy;
});

afterEach(fn () => Carbon::setTestNow());

it('date policy: open month, Reverse on its own date or today; Correct reverses on its own date and posts on the typed date', function () {
    $d = $this->policy->resolve(Carbon::parse('2026-10-15'), DocumentAction::Reverse, ReverseDateChoice::OriginalDate, null, $this->w->financeB);
    expect([$d->reversalDate->toDateString(), $d->rule, $d->needsClosedPeriodPass, $d->blocker])->toBe(['2026-10-15', 'original', false, null])
        ->and($d->sentence)->toBe('Lands on 15 Oct 2026, its own date.');

    $t = $this->policy->resolve(Carbon::parse('2026-10-15'), DocumentAction::Reverse, ReverseDateChoice::Today, null, $this->w->financeB);
    expect([$t->reversalDate->toDateString(), $t->rule])->toBe(['2026-10-17', 'today'])
        ->and($t->sentence)->toBe('Lands today, 17 Oct 2026.');

    $c = $this->policy->resolve(Carbon::parse('2026-10-15'), DocumentAction::Correct, ReverseDateChoice::OriginalDate, Carbon::parse('2026-10-14'), $this->w->financeB);
    expect([$c->reversalDate->toDateString(), $c->successorDate->toDateString(), $c->rule])->toBe(['2026-10-15', '2026-10-14', 'original'])
        ->and($c->sentence)->toBe('The reversal lands on 15 Oct 2026, its own date. The new record posts on 14 Oct 2026.');
});

it('date policy: a closed month is the Owner\'s exception, requestable by anyone else; the successor stays in the month', function () {
    $this->w->closeThrough('2026-09-30');

    $owner = $this->policy->resolve(Carbon::parse('2026-09-15'), DocumentAction::Correct, ReverseDateChoice::OriginalDate, Carbon::parse('2026-09-14'), $this->w->owner);
    expect([$owner->rule, $owner->needsClosedPeriodPass, $owner->blocker, $owner->closedDates])->toBe(['closed_period_exception', true, null, ['2026-09-15', '2026-09-14']]);

    $finance = $this->policy->resolve(Carbon::parse('2026-09-15'), DocumentAction::Reverse, ReverseDateChoice::OriginalDate, null, $this->w->financeB);
    expect($finance->blocker->code)->toBe('period_closed_not_owner')
        ->and($finance->blocker->requestable)->toBeTrue()
        ->and($finance->blocker->message)->toBe('September 2026 is closed. Only the Owner can reverse a closed month.');

    $moved = $this->policy->resolve(Carbon::parse('2026-09-15'), DocumentAction::Correct, ReverseDateChoice::OriginalDate, Carbon::parse('2026-10-01'), $this->w->owner);
    expect($moved->blocker->code)->toBe('closed_month_move')->and($moved->blocker->requestable)->toBeFalse();

    // An unlocked period is open for everyone, as before.
    \App\Modules\Foundation\Models\AccountingPeriod::current()->unlock($this->w->owner, 'Audit');
    $unlocked = $this->policy->resolve(Carbon::parse('2026-09-15'), DocumentAction::Reverse, ReverseDateChoice::OriginalDate, null, $this->w->financeB);
    expect([$unlocked->rule, $unlocked->needsClosedPeriodPass])->toBe(['original', false]);
});

it('the ack token is bound to the user, business, rule, payload and candidates, and expires after 15 minutes', function () {
    $guard = app(DuplicateGuard::class);
    $rule = app(IncomeVoucherDuplicateRule::class);
    $this->w->record('4500', '2026-10-13', head: $this->w->interest);
    $payload = ['received_on' => '2026-10-14', 'money_account_id' => $this->w->bank->id, 'lines' => [['account_id' => $this->w->interest->id, 'amount' => '4500']]];
    $business = $this->w->business->id;

    try {
        $guard->check($rule, $payload, $this->w->financeB, $business, null);
        $this->fail('expected a warning');
    } catch (PossibleDuplicateException $e) {
        $token = $e->warning->ackToken;
    }

    expect($guard->check($rule, $payload, $this->w->financeB, $business, $token))->not->toBeNull();

    foreach ([
        fn () => $guard->check($rule, $payload, $this->w->financeC, $business, $token),
        fn () => $guard->check($rule, ['received_on' => '2026-10-15'] + $payload, $this->w->financeB, $business, $token),
        fn () => $guard->check($rule, $payload, $this->w->financeB, $business, $token.'x'),
    ] as $attempt) {
        expect($attempt)->toThrow(PossibleDuplicateException::class);
    }

    Carbon::setTestNow(Carbon::now()->addMinutes(16));
    expect(fn () => $guard->check($rule, $payload, $this->w->financeB, $business, $token))->toThrow(PossibleDuplicateException::class);
});

it('a reason reads as "picked: typed"', function () {
    $picked = Reason::query()->where('context', 'correction')->where('name', 'Wrong amount')->first();

    expect((new ActionReason($picked->id, 'typed 50,000 for 5,000'))->display())->toBe('Wrong amount: typed 50,000 for 5,000')
        ->and((new ActionReason(null, 'Entered on the wrong day'))->display())->toBe('Entered on the wrong day');
});

it('a document that corrects nothing has no "Correction of" label and is its own root and latest', function () {
    $voucher = $this->w->record();

    expect(CorrectionChain::correctionOfLabel($voucher))->toBeNull()
        ->and(CorrectionChain::root($voucher)->is($voucher))->toBeTrue()
        ->and(CorrectionChain::latest($voucher)->is($voucher))->toBeTrue()
        ->and(CorrectionChain::links($voucher))->toBe([]);
});
