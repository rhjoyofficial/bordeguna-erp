<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.1 "Money transfer:
 * Correct, Reverse"): money moved between the business's own accounts.
 *
 *   Reverse   both accounts move back together, on the transfer's own date
 *   Correct   the same, plus a new transfer from the edited form
 *
 * Owner only, the same as recording one today (`accounting.journal.post`).
 * Refused while either money line is matched on a bank statement.
 */
class MoneyTransferActionPolicy extends AccountsDocumentPolicy
{
    public const TYPE = 'accounting.money_transfer';

    public const PERMISSION = 'accounting.journal.post';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return MoneyTransfer::class;
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
        return [DocumentAction::Correct, DocumentAction::Reverse];
    }

    protected function dateOf(Model $document): Carbon
    {
        return $document->transferred_on->copy()->startOfDay();
    }

    protected function totalMinorOf(Model $document): int
    {
        /** @var MoneyTransfer $document */
        return Money::ofTaka($document->totalWithdrawn())->minor;
    }

    protected function isReversed(Model $document): bool
    {
        return $document->isReversed();
    }

    protected function alreadyDone(Model $document): Blocker
    {
        /** @var MoneyTransfer $document */
        $document->loadMissing('reversedBy:id,name');

        return $this->reversedSentence((string) $document->transfer_number, $document->reversed_at, $document->reversedBy?->name, CorrectionChain::linkAsOriginal($document)?->successor_number);
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->transfer_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.money-transfers.index', ['show_corrections' => 1]).'#transfer-'.$document->getKey();
    }
}
