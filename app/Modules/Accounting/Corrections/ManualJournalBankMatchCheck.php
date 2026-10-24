<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): BankMatchBlocker reads the journals
 * whose SOURCE is the document; a manual journal is the journal itself, so its
 * own lines are what a bank statement may have claimed.
 */
class ManualJournalBankMatchCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::BankMatch;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection()) {
            return null;
        }

        $matched = DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->where('journal_id', $document->getKey())->select('id'))
            ->exists();

        return $matched
            ? new Blocker('bank_matched', "{$action->label()}: matched on the bank statement. Unmatch it first.", url: route('accounting.bank-statements'), resolvableBy: 'accountant')
            : null;
    }
}
