<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingDocument;
use App\Modules\Accounting\Support\AgeingRow;
use App\Modules\Accounting\Support\ReportDates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who owes what, and for how long.
 *
 * ⚠ Two routes, one screen component, one engine. AR and AP are the same report
 * with the arrow reversed, and building them as two screens would guarantee that
 * a fix to one eventually misses the other.
 */
class AgeingController extends Controller
{
    public function receivables(Request $request): Response
    {
        return $this->render(Ageing::receivables($this->asAt($request)), 'receivables');
    }

    public function payables(Request $request): Response
    {
        return $this->render(Ageing::payables($this->asAt($request)), 'payables');
    }

    private function render(Ageing $ageing, string $kind): Response
    {
        $buckets = AgeingBucket::ordered();

        return Inertia::render('accounting/ageing/index', [
            'kind' => $kind,
            'label' => $ageing->label,
            'party_label' => $kind === 'receivables' ? 'Customer' : 'Supplier',
            'as_at' => $ageing->asAt->toDateString(),
            'buckets' => collect($buckets)->map(fn (AgeingBucket $bucket) => [
                'key' => $bucket->value,
                'label' => $bucket->label(),
            ]),
            'rows' => $ageing->rows->map(fn (AgeingRow $row) => [
                'party_id' => $row->partyId,
                'party_name' => $row->partyName,
                // ⚠ Every figure formatted explicitly. `19200.00000000` has
                // reached a real user on this project twice.
                'buckets' => collect($buckets)->mapWithKeys(fn (AgeingBucket $bucket) => [
                    $bucket->value => $row->bucket($bucket)->format(),
                ]),
                'on_account' => $row->onAccount->format(),
                'total' => $row->total()->format(),
                // F33: the row is the other way round (we owe this customer,
                // or this supplier owes us). Shown positive with a word.
                'in_credit' => $row->total()->isCredit(),
                'total_absolute' => $row->total()->absolute()->format(),
                // C-GLA-15. Without the documents the report says "12,500,
                // 31-60 days" and the next question has no answer on the page.
                'documents' => $row->documents->map(fn (AgeingDocument $document) => [
                    'number' => $document->number,
                    'date' => $document->documentDate->toDateString(),
                    'basis_date' => $document->basisDate()->toDateString(),
                    'uses_fallback_date' => $document->usesFallbackDate(),
                    'days_overdue' => max(0, $document->daysOverdue),
                    'bucket' => $document->bucket()->value,
                    'total' => $document->total->format(),
                    'outstanding' => $document->outstanding->format(),
                ])->values(),
            ]),
            'totals' => [
                'buckets' => collect($buckets)->mapWithKeys(fn (AgeingBucket $bucket) => [
                    $bucket->value => $ageing->bucket($bucket)->format(),
                ]),
                'on_account' => $ageing->onAccount()->format(),
                'total' => $ageing->total()->format(),
                // ⚠ F33 (17 Oct 2026): the headline splits the parties by side,
                // so a customer we owe after a return is "we owe customers
                // 807.50", never "-807.50 owed to us". `total` above stays the
                // figure that ties to the control account.
                'owing' => $ageing->totalOwing()->format(),
                'in_credit' => $ageing->totalInCredit()->format(),
            ],
            // ⚠⚠ C-GLA-12. The tie-out to the ledger, on the screen, every time.
            'control' => [
                'account' => $ageing->controlAccountLabel,
                'balance' => $ageing->controlBalance?->format(),
                'ties_out' => $ageing->tiesOut(),
                'difference' => $ageing->difference()->absolute()->format(),
            ],
            'parties_omitted' => $ageing->partiesOmitted,
            'uses_fallback_dates' => $ageing->usesFallbackDates(),
        ]);
    }

    /**
     * ⚠ An ageing is AS AT a date, never FOR a period. "How overdue is this"
     * has no meaning across a range.
     */
    private function asAt(Request $request): Carbon
    {
        return $request->filled('as_at')
            ? ReportDates::parse($request, 'as_at')->startOfDay()
            : Carbon::today();
    }
}
