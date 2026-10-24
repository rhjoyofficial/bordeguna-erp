<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Corrections\AccountsDocumentPolicy;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Sales\Models\CommissionPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Paying owed commission, reversed and kept (25 Oct 2026): its one journal
 * is reversed and the settlement owes that much again. A payment matched on
 * a bank statement is unmatched first (BankMatchBlocker).
 */
class CommissionPaymentActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'sales.commission_payment';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CommissionPayment::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(CommissionSettlementActionPolicy::PERMISSION);
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch], [LedgerEntryCheck::class, BankMatchBlocker::class]);
    }

    protected function correctPermission(): string
    {
        return CommissionSettlementActionPolicy::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->paid_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var CommissionPayment $document */
        return $document->money()->minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var CommissionPayment $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence($this->numberOf($document), $document->reversed_at, $document->reversedBy?->name, null);
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->getAttribute('number');
    }

    public function urlOf(Model $document): ?string
    {
        /** @var CommissionPayment $document */
        $agreementId = $document->settlement?->commission_agreement_id;

        return $agreementId === null ? null : route('sales.commission.show', $agreementId).'#payment-'.$document->getKey();
    }
}
