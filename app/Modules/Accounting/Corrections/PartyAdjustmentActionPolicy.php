<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Party adjustment:
 * Reverse with reason and date choice. The document is kept, marked
 * Reversed"). Before this, "Remove" reversed the journal TODAY and then
 * hard-deleted the write-off, discount or rounding, so nothing said it had
 * ever existed.
 *
 *   Reverse   its own date by default, or Today while the month is open; the
 *             invoices and bills it settled owe again, and which they were is
 *             kept on the adjustment (`reversed_allocations`)
 *
 * Owner only (`accounting.journal.post`), as recording one. An adjustment that
 * another document owns is undone there (PartyAdjustmentOwnerCheck).
 */
class PartyAdjustmentActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.party_adjustment';

    public const PERMISSION = 'accounting.journal.post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return PartyAdjustment::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Allocation, EffectKind::Ledger], [PartyAdjustmentOwnerCheck::class, LedgerEntryCheck::class]);
    }

    protected function correctPermission(): string
    {
        return self::PERMISSION;
    }

    protected function correctionActions(Model $document): array
    {
        return [DocumentAction::Reverse];
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->adjusted_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var PartyAdjustment $document */
        return $document->money()->minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var PartyAdjustment $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence($this->numberOf($document), $document->reversed_at, $document->reversedBy?->name, null);
    }

    public function numberOf(Model $document): string
    {
        return 'ADJ-'.str_pad((string) $document->getKey(), 4, '0', STR_PAD_LEFT);
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.adjustments.index').'#adjustment-'.$document->getKey();
    }
}
