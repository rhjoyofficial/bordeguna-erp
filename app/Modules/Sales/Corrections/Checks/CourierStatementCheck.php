<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\CourierLossClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierBalances;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ B13 (24 Oct 2026): what a posted courier payout did that something else
 * now stands on.
 *
 *   - a loss claim it paid (compensation on one of its lines) is closed by it:
 *     undo that claim's settlement first, or the claim reads paid by money the
 *     books no longer hold;
 *   - the shortfall it left owed to the courier (courier.payable) has since
 *     been netted off a later payout: reversing it would leave the courier owed
 *     less than nothing. Reverse the later statement first.
 */
class CourierStatementCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::External;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof CourierStatement || $document->isReversed() || ! $action->isCorrection()) {
            return null;
        }

        $claim = CourierLossClaim::query()->whereIn('courier_statement_line_id', $document->lines()->select('id'))->first();

        if ($claim !== null) {
            return new Blocker('loss_claim_paid', "Statement {$document->statement_reference} paid a loss claim on one of its parcels, so it cannot be reversed here.");
        }

        $payable = Account::bySlug('courier_payable');
        $document->loadMissing('courier');

        if ($payable !== null && $document->courier !== null) {
            $added = -(int) DB::table('journal_lines')
                ->whereIn('journal_id', Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->whereNull('reverses_journal_id')->select('id'))
                ->where('account_id', $payable->id)
                ->sum('amount_minor');

            if ($added > 0 && CourierBalances::payable($document->courier)->minor - $added < 0) {
                return new Blocker('payable_netted', "The shortfall statement {$document->statement_reference} left owed to the courier was netted off a later payout; reverse that payout first.");
            }
        }

        return null;
    }
}
