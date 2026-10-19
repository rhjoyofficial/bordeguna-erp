<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ A sales document has the ONE live journal it should have (the D1 data
 * check, as LedgerEntryCheck asks it), with the sales module's own exception:
 * PostSalesJournals posts NOTHING when the business's sales posting is off on
 * that date (no `sales.revenue` mapping) or the document is worth nothing to
 * the ledger (a challan of zero-cost samples). Such a document has nothing to
 * mirror, and that is not a data problem.
 *
 * Several live journals, or none where one should be, is a data problem a
 * person must look at; reversing "the first one" would be a guess.
 */
class SalesLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || (method_exists($document, 'isReversed') && $document->isReversed())) {
            return null;
        }

        $live = self::liveJournals($document)->count();

        if ($live === 1 || ($live === 0 && ! self::expectsJournal($document))) {
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

    /** The document's live (not a reversal, not reversed) journals. */
    public static function liveJournals(Model $document): Collection
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->get();
    }

    /** The one live journal, or null when there is none (or, a data problem, several). */
    public static function journalOf(Model $document): ?Journal
    {
        $live = self::liveJournals($document);

        return $live->count() === 1 ? $live->first() : null;
    }

    /** Would PostSalesJournals have posted something for this document? */
    public static function expectsJournal(Model $document): bool
    {
        $on = match (true) {
            $document instanceof Delivery => Carbon::parse($document->delivered_at)->startOfDay(),
            $document instanceof SalesInvoice => $document->invoice_date->copy()->startOfDay(),
            $document instanceof CustomerReceipt => $document->received_on->copy()->startOfDay(),
            default => null,
        };

        if ($on === null || AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return false;
        }

        return match (true) {
            $document instanceof Delivery => ! $document->loadMissing('lines.movements')->costValue()->isZero(),
            $document instanceof SalesInvoice => ! $document->is_opening && ((float) $document->total !== 0.0 || ! $document->loadMissing('lines')->costReleased()->isZero()),
            // A payroll offset and a voucher's receipt post elsewhere.
            $document instanceof CustomerReceipt => $document->isCash() && $document->voucher_id === null && (float) $document->amount !== 0.0,
            default => true,
        };
    }
}
