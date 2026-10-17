<?php

use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * The pure pieces of the correction contract (PHASE-0-CONTRACT v2, section 4,
 * unit layer): no database, no container.
 */
it('formats money in a sentence with English digits and no float', function () {
    expect(Tk::of(500000))->toBe('Tk 5,000')
        ->and(Tk::of(102340))->toBe('Tk 1,023.40')
        ->and(Tk::of(10234000))->toBe('Tk 102,340')
        ->and(Tk::of(-5))->toBe('-Tk 0.05')
        ->and(Tk::date(Carbon::parse('2026-10-15')))->toBe('15 Oct 2026');
});

it('judges the second person on max(original, corrected), in absolute paisa', function () {
    $rule = new SecondPersonRule;

    expect($rule->basis(10_234_000, 102_340))->toBe(10_234_000)   // the Pathao case
        ->and($rule->basis(1_500_000, 1_900_000))->toBe(1_900_000)
        ->and($rule->basis(-3_000_000, null))->toBe(3_000_000);
});

it('routes a correction: who asks, which month, above or below the limit', function () {
    $rule = new SecondPersonRule;

    expect($rule->route(false, false, false))->toBe(ApprovalRoute::None)
        ->and($rule->route(false, false, true))->toBe(ApprovalRoute::SecondPerson)
        ->and($rule->route(true, true, false))->toBe(ApprovalRoute::None)
        ->and($rule->route(true, true, true))->toBe(ApprovalRoute::SecondPerson)
        ->and($rule->route(true, false, false))->toBe(ApprovalRoute::OwnerClosedPeriod)
        ->and($rule->route(true, false, true))->toBe(ApprovalRoute::Both)
        ->and(ApprovalRoute::Both->workflowKey())->toBe('correction_closed_period')
        ->and(ApprovalRoute::SecondPerson->workflowKey())->toBe('correction');
});

it('fingerprints the payload canonically: key order never matters, any value change does', function () {
    $make = fn (array $replacement, string $reason = 'Wrong amount') => new CorrectionInput(
        'accounting.income_voucher', 7, DocumentAction::Correct, new ActionReason(null, $reason), $replacement, ReverseDateChoice::OriginalDate, 'k',
    );

    $a = $make(['received_on' => '2026-10-14', 'lines' => [['amount' => '4500', 'account_id' => 9]]]);
    $b = $make(['lines' => [['account_id' => 9, 'amount' => '4500']], 'received_on' => '2026-10-14']);
    $c = $make(['lines' => [['account_id' => 9, 'amount' => '4501']], 'received_on' => '2026-10-14']);
    $d = $make(['lines' => [['account_id' => 9, 'amount' => '4500']], 'received_on' => '2026-10-14'], 'Other reason');
    $e = $make(['lines' => [['account_id' => '9', 'amount' => 4500]], 'received_on' => '2026-10-14']);

    expect($a->payloadFingerprint())->toBe($b->payloadFingerprint())
        ->and($a->payloadFingerprint())->toBe($e->payloadFingerprint())
        ->and($a->payloadFingerprint())->not->toBe($c->payloadFingerprint())
        ->and($a->payloadFingerprint())->not->toBe($d->payloadFingerprint());
});

it('a pass covers only its own dates, for its own business', function () {
    $pass = new ClosedPeriodPass(1, 10, 'X', 5, 'correct', 'X', ['2026-09-15', '2026-09-14'], 2, 'secret');

    expect($pass->covers(Carbon::parse('2026-09-15'), 10))->toBeTrue()
        ->and($pass->covers(Carbon::parse('2026-09-14 18:00'), 10))->toBeTrue()
        ->and($pass->covers(Carbon::parse('2026-09-16'), 10))->toBeFalse()
        ->and($pass->covers(Carbon::parse('2026-09-15'), 11))->toBeFalse()
        ->and($pass->covers(Carbon::parse('2026-09-15'), null))->toBeFalse();
});
