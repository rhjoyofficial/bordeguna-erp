<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;

/**
 * "The correction date is the original date, everywhere" (FINAL-PLAN section 9,
 * answer 4), PHASE-0-CONTRACT v2 1.9.
 *
 *   1. The reversal lands on the original's own date. Always on Correct.
 *   2. Today is offered only on Reverse, and only when the original's month is
 *      open (a closed month never offers it: today is a different month).
 *   3. The successor takes the date typed in the Correct form; never the future.
 *   4. Open month: any open successor date; no pass.
 *   5. Closed month, the Owner: a one-document exception (needs a pass).
 *      Closed month, anybody else: blocked, but requestable to the Owner.
 *   6. v2 (C6): under a closed-month exception the successor stays inside the
 *      original's own month; ANY other month is refused. An open-month
 *      correction may not move the successor into a closed month either.
 *
 * ⚠ The 12 Oct Pathao lesson: a reversal dated on another day than its original
 * leaves two months each half wrong. That is why Correct never offers Today.
 */
class OriginalDatePolicy implements CorrectionDatePolicy
{
    public function resolve(Carbon $originalDate, DocumentAction $action, ReverseDateChoice $choice, ?Carbon $successorDate, ?User $authority): CorrectionDates
    {
        $originalDate = $originalDate->copy()->startOfDay();
        $successorDate = $successorDate?->copy()->startOfDay();
        $lock = AccountingPeriod::current();
        $closedThrough = $lock !== null && ! $lock->is_unlocked ? $lock->closed_through->copy()->startOfDay() : null;
        $isClosed = fn (Carbon $date) => $closedThrough !== null && $date->lessThanOrEqualTo($closedThrough);
        $month = $originalDate->format('F Y');
        $verb = $action === DocumentAction::Correct ? 'correct' : 'reverse';

        $today = Carbon::today();
        $useToday = $action === DocumentAction::Reverse && $choice === ReverseDateChoice::Today;
        $reversalDate = $useToday ? $today : $originalDate;

        $make = fn (string $rule, string $sentence, bool $pass = false, ?Blocker $blocker = null, array $closedDates = []) => new CorrectionDates(
            reversalDate: $reversalDate,
            successorDate: $action === DocumentAction::Correct ? ($successorDate ?? $originalDate) : null,
            rule: $rule,
            needsClosedPeriodPass: $pass,
            closedThrough: $closedThrough,
            sentence: $sentence,
            blocker: $blocker,
            closedDates: $closedDates,
        );

        if ($choice === ReverseDateChoice::Today && $action !== DocumentAction::Reverse) {
            return $make('original', '', blocker: new Blocker('today_not_allowed', 'A correction always reverses on the record\'s own date.'));
        }

        if ($useToday && $isClosed($originalDate)) {
            return $make('today', '', blocker: new Blocker('today_closed_month', "Today is offered only while {$month} is open."));
        }

        if ($action === DocumentAction::Correct) {
            $successorDate ??= $originalDate;

            if ($successorDate->isAfter($today)) {
                return $make('original', '', blocker: new Blocker('future_date', 'A record cannot be dated in the future.'));
            }

            if ($isClosed($originalDate) && ! $successorDate->isSameMonth($originalDate)) {
                return $make('closed_period_exception', '', blocker: new Blocker('closed_month_move', "A closed-month correction keeps the record in {$month}. To move it to another month, reverse it and record a new one there."));
            }

            if (! $isClosed($originalDate) && $isClosed($successorDate)) {
                return $make('original', '', blocker: new Blocker('successor_closed', 'A correction cannot move the record into another closed month.'));
            }
        }

        $lands = $useToday
            ? 'Lands today, '.Tk::date($today).'.'
            : 'Lands on '.Tk::date($originalDate).', its own date.';

        if ($action === DocumentAction::Correct) {
            $lands = 'The reversal lands on '.Tk::date($originalDate).', its own date. The new record posts on '.Tk::date($successorDate).'.';
        }

        if (! $isClosed($reversalDate)) {
            return $make($useToday ? 'today' : 'original', $lands);
        }

        $closedDates = [$originalDate->toDateString()];

        if ($action === DocumentAction::Correct && $isClosed($successorDate)) {
            $closedDates[] = $successorDate->toDateString();
        }

        $sentence = $lands." {$month} is closed: this posts into {$month} on its own date, as a one-document exception. The month stays closed for everything else.";

        if (Owners::mayCorrectClosedMonth($authority)) {
            return $make('closed_period_exception', $sentence, true, null, array_values(array_unique($closedDates)));
        }

        return $make('closed_period_exception', $sentence, true, new Blocker(
            'period_closed_not_owner',
            "{$month} is closed. Only the Owner can {$verb} a closed month.",
            resolvableBy: 'owner',
            requestable: true,
        ), array_values(array_unique($closedDates)));
    }
}
