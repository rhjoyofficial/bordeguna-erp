<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Other income: which buttons, which blockers (PHASE-0-CONTRACT v2, 3.1).
 *
 *   Reverse   not reversed; one ledger entry; not bank-matched; the month open,
 *             or the Owner (a closed month is requestable to the Owner)
 *   Correct   the same, plus the replacement passes RecordIncomeVoucher's rules
 *   Edit, Delete   NEVER on a posted voucher: it posts the moment it is recorded
 *
 * `effectProfile()` = Ledger + BankMatch, each covered by its check (v2, C14).
 */
class IncomeVoucherActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'accounting.income_voucher';

    public const PERMISSION = 'accounting.correct';

    public function __construct(
        private readonly CorrectionDatePolicy $dates,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return IncomeVoucher::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        // A voucher posts the moment it is recorded.
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch],
            [LedgerEntryCheck::class, BankMatchBlocker::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // ⚠ Somebody without the permission sees no correction buttons at all.
        if ($user->can(self::PERMISSION)) {
            $actions[] = $this->availability($document, $user, DocumentAction::Correct);
            $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var IncomeVoucher $document */
        $offered = in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true);
        $blockers = [];

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a posted voucher.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if ($document->isReversed()) {
            $blockers[] = $this->alreadyDone($document);
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', $document->getMorphClass().':'.$document->getKey())
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$document->voucher_number} is already waiting for approval.");
        }

        if (! $document->isReversed()) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($document->received_on->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $basis = Money::ofTaka((string) $document->total)->minor;
        $above = $action === DocumentAction::Reverse && $this->secondPerson->applies((int) $document->business_id, $basis);

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $offered,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->voucher_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.other-income.print', $document);
    }

    private function alreadyDone(IncomeVoucher $voucher): Blocker
    {
        $voucher->loadMissing(['reversedBy:id,name', 'correctionAsOriginal']);
        $on = $voucher->reversed_at?->format('j M') ?? 'an earlier day';
        $by = $voucher->reversedBy?->name ?? 'somebody';
        $successor = $voucher->correctionAsOriginal?->successor_number;

        return $successor === null
            ? new Blocker('already_reversed', "{$voucher->voucher_number} was already reversed on {$on} by {$by}.")
            : new Blocker('already_corrected', "{$voucher->voucher_number} was already corrected on {$on} by {$by}. Correct {$successor} instead.");
    }
}
