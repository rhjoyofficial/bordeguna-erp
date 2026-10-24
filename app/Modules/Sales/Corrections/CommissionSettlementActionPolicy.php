<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Corrections\AccountsDocumentPolicy;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A commission settlement through the corrections engine (Joy's Q18, 25 Oct
 * 2026). Reverse only, kept and marked Reversed, with a reason and the date
 * choice; its money goes back to "to settle" and the next settlement takes it.
 *
 *   owed / paid now   its one journal is reversed (CommissionLedgerCheck);
 *                     an owed one with live payments is refused until they
 *                     are reversed first
 *   next payroll      the payroll addition is cancelled while that month
 *                     still takes inputs; once the run is reviewed or paid it
 *                     is refused (CommissionPayrollCheck)
 *
 * Who: whoever holds sales.commission.manage. Above the Finance policy limit
 * a second person approves, a closed month is the Owner's (the base class).
 */
class CommissionSettlementActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'sales.commission_settlement';

    public const PERMISSION = 'sales.commission.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CommissionSettlement::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::Payroll], [CommissionLedgerCheck::class, CommissionPayrollCheck::class]);
    }

    protected function correctPermission(): string
    {
        return self::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function isPosted(Model $document): bool
    {
        /** @var CommissionSettlement $document */
        return $document->status === CommissionSettlement::POSTED;
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->settled_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var CommissionSettlement $document */
        return $document->money()->minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var CommissionSettlement $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence($this->numberOf($document), $document->reversed_at, $document->reversedBy?->name, null);
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->getAttribute('number');
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.commission.show', $document->getAttribute('commission_agreement_id')).'#settlement-'.$document->getKey();
    }
}
