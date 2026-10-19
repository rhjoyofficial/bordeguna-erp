<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\ProductionRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a production run (FINAL-PLAN 3.3).
 *
 *   Draft      Edit (no reason: a draft); Delete stays the run page's own
 *              button (with a reason), outside this policy: the policy kit
 *              refuses Delete on any type with effects, and a confirmed run
 *              has them (contract gap, reported)
 *   Confirmed  Reverse (inputs back onto their batches, the output taken out
 *              of the batch it made) or Correct (that, then the right run),
 *              on the run's own date; Production manager
 *   Reversed   nothing more
 *
 * ⚠ Output already sold or used: refused with a sentence. The plan's cost rule
 * (Tk 10,000 to today's cost of goods sold) is the accountant's revaluation,
 * not something this screen guesses (brief: "or refuse with a plain sentence").
 *
 * ⚠ A closed month is refused too, Owner included: a run posts NO journal
 * when its waste is absorbed, and the Owner's closed-month pass is granted for
 * exactly one journal per leg (ClosedPeriodExceptions::close). Contract gap,
 * reported; nothing is bent to fit.
 *
 * `effectProfile()` = Ledger (ProductionLedgerCheck) + Stock (ProductionOutputCheck).
 */
class ProductionRunActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.production_run';

    public const PERMISSION = 'inventory.production.manage';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return ProductionRun::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->can('inventory.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var ProductionRun $document */
        return $document->isDraft() ? DocumentStage::Draft : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock],
            [ProductionLedgerCheck::class, ProductionOutputCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var ProductionRun $document */
        if (! $user->can(self::PERMISSION)) {
            return new ActionSet($this->stageOf($document), []);
        }

        $actions = $document->isDraft()
            ? [$this->availability($document, $user, DocumentAction::Edit)]
            : [$this->availability($document, $user, DocumentAction::Correct), $this->availability($document, $user, DocumentAction::Reverse)];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var ProductionRun $document */
        $number = $this->numberOf($document);

        if ($action === DocumentAction::Edit) {
            $blockers = $document->isDraft() ? [] : [new Blocker('not_draft', "{$number} is confirmed, so it is corrected, not edited.")];

            return StockPolicySupport::plain($action, $user, self::PERMISSION, $blockers, false, null);
        }

        if (! in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true)) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [StockPolicySupport::notOffered($action, 'a production run')], true, null, primary: false);
        }

        $blockers = [];

        if ($document->status === ProductionRunStatus::Reversed) {
            $document->loadMissing('reversedBy:id,name');
            $blockers[] = new Blocker('already_reversed', "{$number} was already reversed on ".($document->reversed_at?->format('j M') ?? 'an earlier day').' by '.($document->reversedBy?->name ?? 'somebody').'.');
        } elseif ($document->isDraft()) {
            $blockers[] = new Blocker('not_confirmed', "{$number} is a draft: edit it instead.");
        } elseif ($this->support->closedOn($this->originalDate($document))) {
            $blockers[] = new Blocker('closed_month_production', $this->originalDate($document)->format('F Y').' is closed, and a production run in a closed month cannot be corrected yet. Ask the administrator.');
        }

        return $this->support->correction(
            $document,
            $user,
            $action,
            $blockers,
            $this->effectProfile(),
            $this->originalDate($document),
            $this->valueMinor($document),
            self::PERMISSION,
            $number,
            runChecks: $document->isConfirmed(),
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->run_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('inventory.production-runs.show', $document);
    }

    public function originalDate(ProductionRun $run): Carbon
    {
        return Carbon::parse($run->occurred_at)->startOfDay();
    }

    public function valueMinor(ProductionRun $run): int
    {
        return $run->output_value === null ? 0 : Money::ofTaka((string) $run->output_value)->absolute()->minor;
    }
}
