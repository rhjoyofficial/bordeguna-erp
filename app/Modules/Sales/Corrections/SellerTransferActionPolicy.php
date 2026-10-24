<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Corrections\StockPolicySupport;
use App\Modules\Sales\Models\SellerTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B21 (R109): a retail seller's hand-over or return in the
 * corrections process. Reverse only: a wrong sheet is reversed and written
 * again (the goods go back onto the batches they left, ReverseSellerTransfer).
 *
 *   Who        Owner, Manager (sales.sellers.manage); a closed month is the
 *              Owner's one-document exception (StockPolicySupport, the engine)
 *   Blockers   already reversed; a correction already waiting; any item that
 *              has moved on since (SellerTransferStockCheck)
 *
 * ⚠ The Tk 10,000 sold-stock rule does not arise: a sheet whose goods were
 * sold is refused, never costed, because the goods are no longer his to hand
 * back. The Tk 20,000 second person reads the sheet's cost value.
 *
 * `effectProfile()` = Stock (SellerTransferStockCheck). No ledger: a
 * transfer posts no journal.
 */
class SellerTransferActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'sales.seller_transfer';

    public const PERMISSION = 'sales.sellers.manage';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SellerTransfer::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Stock], [SellerTransferStockCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var SellerTransfer $document */
        $blockers = [];

        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [StockPolicySupport::notOffered($action, 'a seller sheet')], true, null);
        }

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "{$document->label()} was already reversed.");
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
            number: $document->label(),
            runChecks: ! $document->isReversed(),
        );
    }

    public function documentDate(SellerTransfer $document): Carbon
    {
        return Carbon::parse($document->moved_on->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var SellerTransfer $document */
        return (int) round(((float) $document->cost_value) * 100);
    }

    public function numberOf(Model $document): string
    {
        /** @var SellerTransfer $document */
        return $document->label();
    }

    public function urlOf(Model $document): ?string
    {
        /** @var SellerTransfer $document */
        return route('sales.sellers.show', ['employee' => $document->employee_id]);
    }
}
