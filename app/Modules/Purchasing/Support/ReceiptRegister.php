<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\SupplierBillLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ⭐ Purchase redesign PR2 (18 Oct 2026): the figures the Receipt Notes list
 * and page show for a delivery, derived from the documents every time and
 * never stored, exactly as ReceiptBilling derives what is billable.
 *
 * - Value: what the supplier will invoice for what arrived, the order line's
 *   own value of the quantity (PurchaseOrderLine::valueOf), the figure the
 *   receipt journal credited to GRNI. Paisa.
 * - Bill state: `open` (nothing billed yet), `part` or `billed` (nothing left
 *   to bill), from ReceiptBilling's per-receipt-line billable quantity.
 *   ⚠ Goods that went back to the supplier come off "left to bill" too, as
 *   they do on the bill screen, so a delivery returned in full reads `billed`:
 *   there is nothing left on it for a bill.
 */
final class ReceiptRegister
{
    /** @var array<int, array<int, string>> billable per receipt line, per order line */
    private array $billableByOrderLine = [];

    /** The receipt's value in paisa. */
    public function valueOf(GoodsReceipt $receipt): int
    {
        return $receipt->lines->sum(fn (GoodsReceiptLine $line) => $this->lineValue($line));
    }

    public function lineValue(GoodsReceiptLine $line): int
    {
        $orderLine = $line->purchaseOrderLine;

        return $orderLine === null ? 0 : $orderLine->valueOf((string) $line->quantity_received)->minor;
    }

    /** What is still billable on one receipt line, as a 4-place string. */
    public function billableOn(GoodsReceiptLine $line): string
    {
        $orderLine = $line->purchaseOrderLine;

        if ($orderLine === null) {
            return '0.0000';
        }

        $this->billableByOrderLine[$orderLine->id] ??= ReceiptBilling::billableByReceiptLine($orderLine);

        return $this->billableByOrderLine[$orderLine->id][$line->id] ?? '0.0000';
    }

    /**
     * `billed` when nothing is left to bill, `part` when a bill has touched it
     * (a bill naming one of its lines, or an older bill on the order line that
     * named no receipt), `open` otherwise. ⚠ A return alone never makes a
     * delivery look part-billed.
     */
    public function billState(GoodsReceipt $receipt): string
    {
        $billable = 0;
        $touched = false;

        foreach ($receipt->lines as $line) {
            $billable += PurchaseLineEntry::scaled($this->billableOn($line));
            $touched = $touched || PurchaseLineEntry::scaled((string) $line->purchaseOrderLine?->quantity_invoiced) > 0;
        }

        $touched = $touched || SupplierBillLine::query()
            ->whereIn('goods_receipt_line_id', $receipt->lines->pluck('id')->all())
            ->whereHas('bill', fn ($bill) => $bill->whereNull('reversed_at'))
            ->exists();

        return match (true) {
            $billable <= 0 => 'billed',
            $touched => 'part',
            default => 'open',
        };
    }

    /**
     * Every journal a document posted (and any reversal of it), oldest first,
     * for the "Journal" links.
     *
     * @return Collection<int, array{id: int, number: string, date: ?string, reversal: bool}>
     */
    public static function journalsOf(Model $document): Collection
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'journal_number', 'journal_date', 'reverses_journal_id'])
            ->map(fn (Journal $journal) => [
                'id' => $journal->id,
                'number' => $journal->journal_number,
                'date' => $journal->journal_date?->toDateString(),
                'reversal' => $journal->reverses_journal_id !== null,
            ]);
    }

    public static function taka(int $paisa): string
    {
        return Money::ofMinor($paisa)->toTaka();
    }
}
