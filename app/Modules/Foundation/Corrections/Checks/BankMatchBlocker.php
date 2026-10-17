<?php

namespace App\Modules\Foundation\Corrections\Checks;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A money line the bank statement has claimed is real money the bank saw move.
 * Reversing it would leave the statement matched to a reversed line and the
 * reconciliation agreeing falsely, so it is unmatched first, by a person.
 *
 * The shared form of ReversePurchasingDocument::assertNotMatchedOnBankStatement
 * (PUR-003), reading bank_statement_lines.matched_journal_line_id. The document's
 * journals are every live journal whose source is the document.
 */
class BankMatchBlocker implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::BankMatch;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! in_array($action, [DocumentAction::Reverse, DocumentAction::Correct, DocumentAction::ReverseAndCopy, DocumentAction::Cancel], true)) {
            return null;
        }

        $journalIds = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->pluck('id');

        if ($journalIds->isEmpty()) {
            return null;
        }

        $matched = DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journalIds)->select('id'))
            ->exists();

        if (! $matched) {
            return null;
        }

        return new Blocker(
            'bank_matched',
            "{$action->label()}: matched on the bank statement. Unmatch it first.",
            url: $this->statementsUrl(),
            resolvableBy: 'accountant',
        );
    }

    private function statementsUrl(): ?string
    {
        foreach (['accounting.bank-statements'] as $name) {
            if (app('router')->has($name)) {
                return route($name);
            }
        }

        return null;
    }
}
