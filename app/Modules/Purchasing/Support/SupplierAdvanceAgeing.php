<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use Illuminate\Support\Carbon;

/**
 * Advances to suppliers by age, as at a date (ruling P2: "a separate advance
 * ageing view; do not misclassify an unapplied asset as an overdue AP
 * invoice").
 *
 * ⚠ Aged from the day the money left (the advance's date, or the voucher's for
 * the on-account balance), because an advance has no due date: the question it
 * answers is "how long has this supplier held our money without goods".
 *
 * ⚠ As at a date, like the payables ageing: an application, refund or
 * reversal dated later does not count yet. And it is checked against the
 * ledger the same way: the total must equal 1160 Advances to Suppliers on that
 * date (SupplierAdvanceBook states the identity).
 */
final class SupplierAdvanceAgeing
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, control: array<string, mixed>}
     */
    public static function asAt(Carbon $asAt): array
    {
        $on = $asAt->toDateString();
        $documents = [];

        $advances = SupplierAdvance::query()
            ->whereDate('paid_on', '<=', $on)
            ->standingOn($on)
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        $used = fn (string $class, string $dateColumn) => $class::query()
            ->whereIn('supplier_advance_id', $advances->pluck('id'))
            ->whereDate($dateColumn, '<=', $on)
            ->standingOn($on)
            ->get(['supplier_advance_id', 'amount'])
            ->groupBy('supplier_advance_id')
            ->map(fn ($rows) => Money::sum($rows->map(fn ($row) => Money::ofTaka((string) $row->amount)))->minor);

        $applied = $used(SupplierAdvanceApplication::class, 'applied_on');
        $refunded = $used(SupplierAdvanceRefund::class, 'refunded_on');

        foreach ($advances as $advance) {
            $open = $advance->money()->minor - ($applied[$advance->id] ?? 0) - ($refunded[$advance->id] ?? 0);

            if ($open !== 0) {
                $documents[$advance->supplier_id][] = self::document($advance->number, $advance->paid_on->toDateString(), $advance->money(), Money::ofMinor($open), $asAt);
            }
        }

        $account = SupplierAdvanceBook::account();
        $supplierMorph = (new Supplier)->getMorphClass();

        $poolSuppliers = $account === null ? collect() : JournalLine::query()
            ->where('account_id', $account->id)
            ->where('party_type', $supplierMorph)
            ->distinct()
            ->pluck('party_id');

        foreach (Supplier::query()->whereIn('id', $poolSuppliers)->get() as $supplier) {
            $pool = SupplierAdvanceBook::onAccountLots($supplier, $asAt);

            foreach ($pool['lots'] as $lot) {
                $documents[$supplier->id][] = self::document("On account ({$lot['reference']})", $lot['date'], $lot['open'], $lot['open'], $asAt);
            }

            // ⚠ A negative on-account balance (credited after it was used) is
            // shown, not hidden: it is what keeps the total tied to 1160.
            if ($pool['balance']->minor < 0) {
                $documents[$supplier->id][] = self::document('On account, overdrawn', $on, $pool['balance'], $pool['balance'], $asAt);
            }
        }

        $names = Supplier::query()->whereIn('id', array_keys($documents))->pluck('name', 'id');
        $buckets = AgeingBucket::ordered();
        $rows = [];
        $bucketTotals = array_fill_keys(array_map(fn (AgeingBucket $b) => $b->value, $buckets), 0);
        $grand = 0;

        foreach ($documents as $supplierId => $list) {
            $byBucket = array_fill_keys(array_keys($bucketTotals), 0);

            foreach ($list as $document) {
                $byBucket[$document['bucket']] += $document['open_minor'];
            }

            $total = array_sum($byBucket);
            $grand += $total;

            foreach ($byBucket as $key => $minor) {
                $bucketTotals[$key] += $minor;
            }

            $rows[] = [
                'party_id' => $supplierId,
                'party_name' => $names[$supplierId] ?? '-',
                'buckets' => array_map(fn (int $minor) => Money::ofMinor($minor)->format(), $byBucket),
                'total' => Money::ofMinor($total)->format(),
                'documents' => array_map(fn (array $d) => array_diff_key($d, ['open_minor' => true]), $list),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['party_name'], $b['party_name']));

        $ledger = $account === null ? null : (int) JournalLine::query()
            ->where('journal_lines.account_id', $account->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereDate('journals.journal_date', '<=', $on)
            ->sum('journal_lines.amount_minor');

        return [
            'rows' => $rows,
            'totals' => [
                'buckets' => array_map(fn (int $minor) => Money::ofMinor($minor)->format(), $bucketTotals),
                'total' => Money::ofMinor($grand)->format(),
            ],
            'control' => [
                'account' => $account?->label(),
                'balance' => $ledger === null ? null : Money::ofMinor($ledger)->format(),
                'ties_out' => $ledger === null || $ledger === $grand,
                'difference' => Money::ofMinor(abs(($ledger ?? 0) - $grand))->format(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function document(string $number, string $date, Money $total, Money $open, Carbon $asAt): array
    {
        $days = (int) Carbon::parse($date)->startOfDay()->diffInDays($asAt->copy()->startOfDay(), false);

        return [
            'number' => $number,
            'date' => $date,
            'days' => max(0, $days),
            'bucket' => AgeingBucket::forDaysOverdue($days)->value,
            'total' => $total->format(),
            'open' => $open->format(),
            'open_minor' => $open->minor,
        ];
    }
}
