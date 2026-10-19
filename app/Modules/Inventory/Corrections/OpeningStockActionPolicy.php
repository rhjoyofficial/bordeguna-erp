<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Models\OpeningStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): opening stock (FINAL-PLAN 3.3, 4.2
 * "Opening balances: Correct into Opening Balance Equity at the cutover date").
 *
 *   Correct   quantity, cost, batch number, expiry, a line taken out, a
 *             forgotten product added; the difference posts to Opening
 *             Balance Equity (3900) on the opening date, NEVER to Inventory
 *             Write-offs (5200); while that date's month is open, or by the
 *             Owner as a one-document exception (or a request to the Owner)
 *   Reverse   not offered: opening stock is a fact about day one, and taking
 *             it all out is a correction to zero lines, which Correct says
 *   Edit, Delete   never: it moved stock the moment it was recorded
 *
 * Who: Store (`inventory.stock.adjust`, the permission that records opening
 * stock), with the second person of the Tk 20,000 rule when the document is
 * worth more (the engine's rule, judged on the whole document).
 *
 * `effectProfile()` = Ledger (OpeningStockLedgerCheck) + Stock (OpeningStockBatchCheck).
 */
class OpeningStockActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.opening_stock';

    public const PERMISSION = 'inventory.stock.adjust';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return OpeningStock::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('inventory.stock.adjust') || $user->can('inventory.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        // It moves stock the moment it is recorded.
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock],
            [OpeningStockLedgerCheck::class, OpeningStockBatchCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        // ⚠ Somebody who may not record opening stock sees no Correct at all.
        $actions = $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Correct)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var OpeningStock $document */
        $number = $this->numberOf($document);

        if ($action !== DocumentAction::Correct) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [
                new Blocker('not_offered', "{$action->label()} is not offered on opening stock. Correct it instead."),
            ], true, null, primary: false);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $document->loadMissing(['reversedBy:id,name', 'correctionAsOriginal']);
            $successor = $document->correctionAsOriginal?->successor_number;
            $blockers[] = new Blocker(
                'already_corrected',
                "{$number} was already corrected on ".($document->reversed_at?->format('j M') ?? 'an earlier day').' by '.($document->reversedBy?->name ?? 'somebody').'.'
                    .($successor === null ? '' : " Correct {$successor} instead."),
            );
        } elseif ($this->support->closedOn($this->originalDate($document)) && $this->journalOf($document) === null) {
            // The closed-month pass is granted for exactly one journal per leg.
            $blockers[] = new Blocker('closed_no_journal', "{$number} posted nothing to the ledger, so its closed month cannot take a correction. Ask the administrator.");
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
            runChecks: ! $document->isReversed(),
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var OpeningStock $document */
        return "Opening stock #{$document->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('inventory.opening-stock.index');
    }

    public function originalDate(OpeningStock $opening): Carbon
    {
        return Carbon::parse($opening->as_at)->startOfDay();
    }

    public function valueMinor(OpeningStock $opening): int
    {
        $opening->loadMissing('lines.stockMovement');

        return $opening->totalValue()->absolute()->minor;
    }

    public function journalOf(OpeningStock $opening): ?Journal
    {
        return Journal::query()
            ->where('source_type', $opening->getMorphClass())
            ->where('source_id', $opening->getKey())
            ->whereNull('reverses_journal_id')
            ->orderBy('id')
            ->first();
    }
}
