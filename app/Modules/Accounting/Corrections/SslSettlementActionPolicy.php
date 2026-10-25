<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): a posted SSL settlement in the corrections
 * process. Reverse only: the bank, the SSL charge and the clearing account
 * move back together on the settlement's own date (one journal). To change
 * one, reverse it and record it again.
 *
 *   Who        accounting.journal.post (the same key as a money transfer)
 *   Blockers   already reversed; a correction already waiting; the bank line
 *              matched on a bank statement (BankMatchBlocker); not exactly one
 *              live journal (LedgerEntryCheck); a closed month is the Owner's
 */
class SslSettlementActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.ssl_settlement';

    public const PERMISSION = 'accounting.journal.post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SslSettlement::class;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch], [LedgerEntryCheck::class, BankMatchBlocker::class]);
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
        /** @var SslSettlement $document */
        return $document->settled_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var SslSettlement $document */
        return Money::ofTaka((string) $document->gross)->minor;
    }

    protected function isReversed(Model $document): bool
    {
        /** @var SslSettlement $document */
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var SslSettlement $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence((string) $document->number, $document->reversed_at, $document->reversedBy?->name, null);
    }

    public function numberOf(Model $document): string
    {
        /** @var SslSettlement $document */
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.ssl-settlements.index', ['show_corrections' => 1]).'#ssl-'.$document->getKey();
    }
}
