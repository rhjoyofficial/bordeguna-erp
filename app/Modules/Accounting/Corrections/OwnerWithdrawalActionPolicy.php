<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Corrections\StockPolicySupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a POSTED owner personal
 * withdrawal in the corrections process. Reverse only: the goods go back onto
 * the batches they left and the drawings journal is mirrored
 * (ReverseOwnerWithdrawal). A draft keeps the screen's own Edit and Delete,
 * an authorised one its Reopen; those are not engine corrections.
 *
 *   Who        accounting.owner_withdrawal.manage (Owner, Finance); a closed
 *              month is the Owner's one-document exception (the engine)
 *   Blockers   already reversed; a correction already waiting; a batch
 *              re-costed since (OwnerWithdrawalStockCheck); not exactly one
 *              live journal (LedgerEntryCheck)
 *
 * The Tk 20,000 second person reads the withdrawal's cost total. The Tk
 * 10,000 sold-stock rule does not arise: the goods come back, nothing that
 * was sold is re-costed.
 */
class OwnerWithdrawalActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'accounting.owner_withdrawal';

    public const PERMISSION = 'accounting.owner_withdrawal.manage';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return OwnerWithdrawal::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('accounting.owner_withdrawal.view') || $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var OwnerWithdrawal $document */
        return match (true) {
            $document->isPosted() => DocumentStage::Done,
            $document->isApproved() => DocumentStage::ApprovedNoEffect,
            default => DocumentStage::Draft,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Stock, EffectKind::Ledger], [OwnerWithdrawalStockCheck::class, LedgerEntryCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var OwnerWithdrawal $document */
        $actions = $document->isPosted() && $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var OwnerWithdrawal $document */
        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [StockPolicySupport::notOffered($action, 'an owner withdrawal')], true, null);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "Owner personal withdrawal {$document->number} was already reversed.");
        } elseif (! $document->isPosted()) {
            $blockers[] = new Blocker('not_posted', "Owner personal withdrawal {$document->number} is not posted, so there is nothing to reverse.");
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

    public function documentDate(OwnerWithdrawal $document): Carbon
    {
        return Carbon::parse($document->withdrawn_on->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var OwnerWithdrawal $document */
        return (int) $document->cost_total_paisa;
    }

    public function numberOf(Model $document): string
    {
        /** @var OwnerWithdrawal $document */
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.owner-withdrawals.index');
    }
}
