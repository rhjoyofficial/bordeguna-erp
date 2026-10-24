<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DateRuleSummary;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an approved payroll adjustment, or
 * one the system made (FINAL-PLAN 3.4; HRM plan S3 and S4).
 *
 *   month still takes inputs   Cancel: the line is dropped from that month's
 *                              pay (PayrollAdjustment::scopePayable skips it)
 *   month closed for inputs    Cancel: the line stays where it was paid, and
 *                              an OPPOSITE line goes on the first month that
 *                              still takes inputs (rule #18, "a payroll input
 *                              for a month already paid goes to the next
 *                              payroll month")
 *
 * ⛔ A locked or paid run is never touched (August 2026 is locked at net
 * 230,805): its payslips stand, and the opposite line is a new row in a later
 * month.
 *
 * ⚠ A line that pays an expense claim is cancelled from the CLAIM (Reverse
 * payment), so the claim never reads "Added to salary" with nothing behind it.
 *
 * Who: the payroll manager (`hrm.payroll.manage`). The module's own action
 * (CancelPayrollAdjustment); no journal of its own, so no date exception and
 * no Tk 20,000 rule: the payroll run that pays it posts it.
 */
class PayrollAdjustmentActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.payroll_adjustment';

    public const PERMISSION = 'hrm.payroll.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return PayrollAdjustment::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var PayrollAdjustment $document */
        if ($document->isGenerated() || $document->isApproved()) {
            return PayrollInputWindow::accepts($document->period) ? DocumentStage::ApprovedNoEffect : DocumentStage::Done;
        }

        return $document->approvalRequest?->status === ApprovalStatus::Pending ? DocumentStage::Waiting : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        // Engine never runs this type; the guards are the blockers below.
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var PayrollAdjustment $document */
        $actions = [];

        if ($user->can(self::PERMISSION) && ($document->isGenerated() || $document->isApproved())) {
            $actions[] = $this->availability($document, $user, DocumentAction::Cancel);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var PayrollAdjustment $document */
        $blockers = [];
        $plan = null;

        if ($action !== DocumentAction::Cancel) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a payroll adjustment.");
        } else {
            if (! $user->can(self::PERMISSION)) {
                $blockers[] = Blocker::noPermission($action);
            }

            $blockers = [...$blockers, ...$this->cancelBlockers($document)];

            if ($blockers === []) {
                $plan = $this->plan($document);

                if ($plan['blocker'] !== null) {
                    $blockers[] = $plan['blocker'];
                }
            }
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $action === DocumentAction::Cancel,
            requiresReason: true,
            reasonContext: 'cancellation',
            approval: ApprovalRoute::None,
            date: $plan === null || $plan['sentence'] === '' ? null : new DateRuleSummary($plan['offset'] === null ? 'dropped' : 'next_payroll_run', $plan['sentence']),
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var PayrollAdjustment $document */
        return "Adjustment #{$document->id}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('hrm.payroll.inputs.index');
    }

    /**
     * What a cancel does: drop the line from its own month, or put an opposite
     * line on a later one.
     *
     * @return array{offset: ?PayrollPeriod, opposite: ?PayrollAdjustmentType, sentence: string, blocker: ?Blocker}
     */
    public function plan(PayrollAdjustment $adjustment): array
    {
        $period = $adjustment->period;
        $amount = Tk::of((int) round(((float) $adjustment->amount) * 100));

        if (PayrollInputWindow::accepts($period)) {
            $run = $period?->latestRun();
            $sentence = "Dropped from the {$period?->label()} payroll.";

            if ($run !== null && $run->status === PayrollStatus::Calculated) {
                $sentence .= ' Recalculate that payroll so its payslips drop it too.';
            }

            return ['offset' => null, 'opposite' => null, 'sentence' => $sentence, 'blocker' => null];
        }

        $next = $this->nextOpenMonth($period);

        if ($next === null) {
            return ['offset' => null, 'opposite' => null, 'sentence' => '', 'blocker' => new Blocker('no_open_month', 'No later payroll month takes inputs yet, so there is nowhere to put the opposite line.')];
        }

        $opposite = self::opposite($adjustment->type);

        return [
            'offset' => $next,
            'opposite' => $opposite,
            'sentence' => "The {$period->label()} payroll is ".strtolower((string) $period->latestRun()?->status->label()).", so it stays as paid and an opposite {$opposite->label()} of {$amount} goes on the {$next->label()} payroll.",
            'blocker' => null,
        ];
    }

    /** An earning is taken back by a deduction; a deduction is given back by an addition. */
    public static function opposite(PayrollAdjustmentType $type): PayrollAdjustmentType
    {
        return $type->isEarning() ? PayrollAdjustmentType::Deduction : PayrollAdjustmentType::Addition;
    }

    /** @return list<Blocker> */
    private function cancelBlockers(PayrollAdjustment $adjustment): array
    {
        if ($adjustment->isCancelled()) {
            $adjustment->loadMissing('cancelledBy:id,name');

            return [new Blocker('already_cancelled', "Adjustment #{$adjustment->id} was already cancelled on ".$adjustment->cancelled_at->format('j M').' by '.($adjustment->cancelledBy?->name ?? 'somebody').'.')];
        }

        if (! $adjustment->isGenerated() && ! $adjustment->isApproved()) {
            return [new Blocker('not_approved', "Adjustment #{$adjustment->id} is not approved, so there is nothing to cancel.")];
        }

        if ($adjustment->type === PayrollAdjustmentType::ExpenseClaimSettlement
            || $adjustment->origin_type === (new ExpenseClaim)->getMorphClass()) {
            $number = ExpenseClaim::query()->whereKey($adjustment->origin_id)->value('claim_number') ?? 'its claim';

            return [new Blocker('pays_a_claim', "This line pays expense claim {$number}, so use Reverse payment on the claim.")];
        }

        return [];
    }

    /** The first month after `$period` that still takes inputs (opened if it does not exist yet). */
    private function nextOpenMonth(?PayrollPeriod $period): ?PayrollPeriod
    {
        if ($period === null) {
            return null;
        }

        $at = Carbon::create((int) $period->year, (int) $period->month, 1);

        for ($step = 0; $step < 24; $step++) {
            $at = $at->copy()->addMonthNoOverflow();
            $candidate = PayrollPeriod::query()->where('year', $at->year)->where('month', $at->month)->first();

            // A month with no period row yet takes inputs: the cancel opens it.
            if ($candidate === null || PayrollInputWindow::accepts($candidate)) {
                return $candidate ?? new PayrollPeriod(['year' => $at->year, 'month' => $at->month]);
            }
        }

        return null;
    }
}
