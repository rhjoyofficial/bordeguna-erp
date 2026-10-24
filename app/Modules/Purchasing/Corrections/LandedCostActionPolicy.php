<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Corrections\StockPolicySupport;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Purchasing\Actions\ReverseLandedCost;
use App\Modules\Purchasing\Models\LandedCost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a POSTED landed cost in the
 * corrections process. Reverse only (ReverseLandedCost): its cost off the
 * batches still on hand, the part on goods sold since to cost of sales today
 * (the Tk 10,000 rule), its journal mirrored, the bills it raised reversed.
 * A draft keeps the screen's own Edit, Delete, Submit, Approve and Post.
 *
 *   Who        purchasing.landed_cost.post (who may post one may reverse
 *              one); a closed month is the Owner's one-document exception
 *   Blockers   already reversed; a correction already waiting; a bill it
 *              raised paid, credited or discounted; the batch cost changed
 *              again or goods moved on in a way the sum cannot follow
 *              (LandedCostStockCheck); not exactly one live journal
 *              (LedgerEntryCheck); a money line matched on the bank
 *              statement (BankMatchBlocker); above Tk 10,000 on goods sold
 *              since, only the Owner (SoldStock)
 *
 * The Tk 20,000 second person reads the landed cost's own total.
 */
class LandedCostActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'purchasing.landed_cost';

    public const PERMISSION = 'purchasing.landed_cost.post';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return LandedCost::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.landed_cost.view') || $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var LandedCost $document */
        return match (true) {
            $document->isPosted() => DocumentStage::Done,
            $document->isAwaitingApproval() => DocumentStage::Waiting,
            $document->isDraft() => DocumentStage::Draft,
            default => DocumentStage::ApprovedNoEffect,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Stock, EffectKind::Ledger, EffectKind::BankMatch],
            [LandedCostStockCheck::class, LedgerEntryCheck::class, BankMatchBlocker::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var LandedCost $document */
        $actions = $document->isPosted() && $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var LandedCost $document */
        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [StockPolicySupport::notOffered($action, 'a posted landed cost')], true, null);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "Landed cost {$document->number} was already reversed.");
        } elseif (! $document->isPosted()) {
            $blockers[] = new Blocker('not_posted', "Landed cost {$document->number} is not posted, so there is nothing to reverse.");
        } elseif (($owner = SoldStock::ownerBlocker(ReverseLandedCost::plan($document)['sold'], $user)) !== null) {
            // The Tk 10,000 rule: above it, the cost on goods sold since is the Owner's.
            $blockers[] = $owner;
        }

        return $this->support->correction(
            document: $document,
            user: $user,
            action: $action,
            blockers: $blockers,
            profile: $this->effectProfile(),
            originalDate: $this->documentDate($document),
            basisMinor: $this->totalMinor($document),
            permission: self::PERMISSION,
            number: $document->number,
            runChecks: $blockers === [],
        );
    }

    public function documentDate(LandedCost $document): Carbon
    {
        return Carbon::parse($document->incurred_on->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var LandedCost $document */
        return (int) $document->total_paisa;
    }

    public function numberOf(Model $document): string
    {
        /** @var LandedCost $document */
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        /** @var LandedCost $document */
        return route('purchasing.landed-costs.index', ['receipt' => $document->goods_receipt_id]);
    }
}
