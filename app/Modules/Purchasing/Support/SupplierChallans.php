<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\GoodsReceipt;

/**
 * ⭐ THE SAME CHALLAN NUMBER FROM THE SAME SUPPLIER IS A DOUBLE ENTRY (Joy,
 * 26 Sep 2026, Q10: "Refuse, double entry"). A supplier's delivery note number
 * is how their book and ours agree on one delivery; the second receipt with
 * that number is almost always the same goods typed twice, which doubles the
 * stock and the bill.
 *
 * ⚠ Per SUPPLIER, not per business: two suppliers may both number their
 * challans from 1. Compared trimmed and case-folded, because "ch-0021" and
 * "CH-0021 " are one piece of paper. A blank number never collides: plenty of
 * what is bought arrives with no paper at all.
 */
final class SupplierChallans
{
    public static function duplicateOf(int $supplierId, ?string $challanNumber, ?int $ignoreReceiptId = null): ?GoodsReceipt
    {
        $number = self::normalise($challanNumber);

        if ($number === null) {
            return null;
        }

        // ⭐ Corrections wave 1 (19 Oct 2026): a reversed or corrected receipt
        // no longer holds its challan; the corrected one carries the same paper.
        return GoodsReceipt::query()
            ->live()
            ->whereHas('purchaseOrder', fn ($order) => $order->where('supplier_id', $supplierId))
            ->whereNotNull('supplier_challan_number')
            ->whereRaw('lower(trim(supplier_challan_number)) = ?', [$number])
            ->when($ignoreReceiptId !== null, fn ($query) => $query->whereKeyNot($ignoreReceiptId))
            ->with('purchaseOrder:id,po_number')
            ->orderBy('id')
            ->first();
    }

    /** The sentence the person typing reads, or null when there is no duplicate. */
    public static function refusal(int $supplierId, ?string $challanNumber, string $supplierName): ?string
    {
        $earlier = self::duplicateOf($supplierId, $challanNumber);

        if ($earlier === null) {
            return null;
        }

        $when = $earlier->received_at?->format('j M Y') ?? 'earlier';
        $against = $earlier->purchaseOrder?->po_number ?? 'another order';

        return "Challan {$challanNumber} from {$supplierName} was already received on {$when} against {$against}. A delivery note arrives once: if this is a second delivery, it carries a different number.";
    }

    private static function normalise(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
