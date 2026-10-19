<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Purchase returns in the corrections process (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.3 "Purchase return: Reverse: goods back into the same
 * batch at the same cost. Date choice added. When no supplier credit note
 * uses it."). Reverse only: a wrong return is reversed and recorded again.
 *
 * `effectProfile()` = Ledger (PurchaseReturnLedgerCheck) + Stock (the batches
 * it relieved, PurchaseReturnStockCheck) + Allocation (a credit note settling
 * it, PurchaseReturnCreditCheck).
 *
 * ⚠ The Tk 20,000 second person is judged on the return's value (what its
 * journal moved), as for every other money document.
 */
class PurchaseReturnActionPolicy extends PurchasingDocumentPolicy
{
    public const TYPE = 'purchasing.purchase_return';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return PurchaseReturn::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock, EffectKind::Allocation],
            [PurchaseReturnLedgerCheck::class, PurchaseReturnStockCheck::class, PurchaseReturnCreditCheck::class],
        );
    }

    protected function offered(): array
    {
        return [DocumentAction::Reverse];
    }

    public function documentDate(Model $document): Carbon
    {
        /** @var PurchaseReturn $document */
        return Carbon::parse(($document->returned_at ?? $document->created_at)->toDateString())->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var PurchaseReturn $document */
        return $document->invoiceableValue()->minor;
    }

    public function numberOf(Model $document): string
    {
        /** @var PurchaseReturn $document */
        return $document->label();
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.returns.show', $document->getKey());
    }
}
