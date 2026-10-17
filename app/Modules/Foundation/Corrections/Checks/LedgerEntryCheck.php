<?php

namespace App\Modules\Foundation\Corrections\Checks;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * The document has exactly ONE live journal to reverse (data check D1).
 *
 * A document with none, or with several, is a data problem a person must look
 * at (`php artisan erp:corrections-preflight` lists them). Reversing "the first
 * one" would be a guess, so the action is refused instead.
 */
class LedgerEntryCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection()) {
            return null;
        }

        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->count();

        if ($live === 1 || method_exists($document, 'isReversed') && $document->isReversed()) {
            return null;
        }

        return new Blocker(
            'no_ledger_entry',
            $live === 0
                ? 'This record has no ledger entry. Ask the administrator to check it.'
                : 'This record has more than one ledger entry. Ask the administrator to check it.',
            resolvableBy: 'administrator',
        );
    }
}
