<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;

/**
 * Bills that LOOK like the one being recorded (ruling P5, 19 Oct 2026).
 *
 * The exact same invoice number from the same supplier is refused outright
 * (RecordSupplierBill, and the database index behind it). What this finds is
 * the likely duplicate the exact check misses:
 *
 *   1. the same number written differently: "INV-0012", "inv 12", "INV/12"
 *      are one piece of paper. Compared with case, spaces and punctuation
 *      gone and leading zeros dropped from each run of digits;
 *   2. the same amount, to the paisa, dated within WINDOW_DAYS of it, which
 *      is how a bill typed twice under two numbers usually looks.
 *
 * Only live bills of the same supplier count; a reversed bill owes nothing.
 * A match is a WARNING: the person may record the bill anyway with a reason,
 * which is kept on the bill with the bills it resembled.
 */
final class SupplierBillDuplicates
{
    /** Days either side of the bill date within which an equal amount is suspect. */
    public const WINDOW_DAYS = 7;

    public static function normalise(?string $number): string
    {
        // Leading zeros go per run of digits FIRST, so "2026/007" keeps its two
        // numbers apart ("20267"), then everything but letters and digits.
        $upper = mb_strtoupper(trim((string) $number));
        $unpadded = preg_replace_callback('/\d+/', fn (array $m) => ltrim($m[0], '0') === '' ? '0' : ltrim($m[0], '0'), $upper) ?? $upper;

        return preg_replace('/[^A-Z0-9]/', '', $unpadded) ?? '';
    }

    /**
     * @return list<array{id: int, bill_number: string, bill_date: ?string, total: string, why: string}>
     */
    public static function find(Supplier $supplier, string $billNumber, mixed $billDate, Money $total, ?int $ignoreBillId = null): array
    {
        $date = Carbon::parse($billDate)->startOfDay();
        $key = self::normalise($billNumber);

        $candidates = SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('reversed_at')
            ->whereNull('landed_cost_id')
            ->when($ignoreBillId !== null, fn ($q) => $q->whereKeyNot($ignoreBillId))
            ->get(['id', 'bill_number', 'bill_date', 'total']);

        $matches = [];

        foreach ($candidates as $bill) {
            $why = null;

            if ($key !== '' && self::normalise($bill->bill_number) === $key) {
                $why = "the same invoice number written as {$bill->bill_number}";
            } elseif (! $total->isZero()
                && Money::ofTaka((string) $bill->total)->minor === $total->minor
                && $bill->bill_date !== null
                && abs($bill->bill_date->copy()->startOfDay()->diffInDays($date, false)) <= self::WINDOW_DAYS) {
                $why = 'the same amount within '.self::WINDOW_DAYS.' days';
            }

            if ($why !== null) {
                $matches[] = [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                    'why' => $why,
                ];
            }
        }

        return $matches;
    }

    /** The sentence the person typing reads. */
    public static function sentence(Supplier $supplier, array $matches): string
    {
        $parts = array_map(
            fn (array $m) => "{$m['bill_number']} of ".Carbon::parse($m['bill_date'])->format('d/m/Y')." (Tk {$m['total']}, {$m['why']})",
            $matches,
        );

        return "This looks like a bill already recorded for {$supplier->name}: ".implode('; ', $parts).'. If it is a different bill, say why and record it anyway.';
    }
}
