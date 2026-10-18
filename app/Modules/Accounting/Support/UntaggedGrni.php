<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ GRNI NOT TAGGED TO A SUPPLIER (A1, ChatGPT ruling P1, 18 Oct 2026): the
 * exception list for the Goods Received Not Invoiced lines posted before GRNI
 * named its supplier, and for the orders still finishing on that path
 * (Purchasing\Support\GrniParty).
 *
 * ⚠⚠ NOTHING IS REWRITTEN AND NOTHING IS GUESSED. Posted lines are immutable,
 * so they stay untagged in the ledger. This list only says which supplier
 * each line belongs to WHERE A DOCUMENT LINK SAYS SO EXACTLY:
 *   - a goods receipt or purchase return: through its purchase order;
 *   - a supplier bill or credit note: the supplier the document names.
 * A reversal follows the document it reverses (it keeps the same source). A
 * line with no such document (a hand journal on 2120) is listed under
 * "supplier unknown", to be settled from the paperwork, never assigned.
 */
final class UntaggedGrni
{
    /**
     * Every untagged GRNI line, oldest first, with the supplier it derives to.
     * `received_minor` is owed-positive (a receipt's credit is positive, a
     * bill's relief negative), so a supplier's sum is what is still received
     * and not billed on the untagged path.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rows(?Carbon $on = null): Collection
    {
        $account = self::account($on ?? Carbon::today());

        if ($account === null) {
            return collect();
        }

        $lines = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereNull('journal_lines.party_id')
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no')
            ->select('journal_lines.*')
            ->with('journal.source')
            ->get();

        $lines->loadMorph('journal.source', [
            GoodsReceipt::class => ['purchaseOrder:id,po_number,supplier_id'],
            PurchaseReturn::class => ['purchaseOrder:id,po_number,supplier_id'],
            SupplierBill::class => ['purchaseOrder:id,po_number'],
            SupplierCreditNote::class => ['purchaseReturn.purchaseOrder:id,po_number'],
        ]);

        $names = Supplier::query()->pluck('name', 'id');

        return $lines->map(function (JournalLine $line) use ($names) {
            $source = $line->journal->source;
            [$supplierId, $basis] = self::derive($source);

            return [
                'id' => $line->id,
                'journal_id' => $line->journal_id,
                'journal_number' => $line->journal->journal_number,
                'date' => $line->journal->journal_date->toDateString(),
                'document' => SupplierLedger::document($line->journal),
                ...SupplierLedger::references($source),
                'supplier_id' => $supplierId,
                'supplier' => $supplierId === null ? null : ($names[$supplierId] ?? "Supplier #{$supplierId}"),
                'basis' => $basis,
                'received_minor' => -(int) $line->amount_minor,
            ];
        })->values();
    }

    /**
     * The supplier a source document names exactly, and how.
     *
     * @return array{0: int|null, 1: string}
     */
    private static function derive(mixed $source): array
    {
        return match (true) {
            $source instanceof GoodsReceipt && $source->purchaseOrder?->supplier_id !== null => [(int) $source->purchaseOrder->supplier_id, 'Goods receipt, through its purchase order'],
            $source instanceof PurchaseReturn && $source->purchaseOrder?->supplier_id !== null => [(int) $source->purchaseOrder->supplier_id, 'Purchase return, through its purchase order'],
            $source instanceof SupplierBill && $source->supplier_id !== null => [(int) $source->supplier_id, 'The bill names the supplier'],
            $source instanceof SupplierCreditNote && $source->supplier_id !== null => [(int) $source->supplier_id, 'The credit note names the supplier'],
            default => [null, 'No document links this line to a supplier'],
        };
    }

    private static function account(Carbon $on): ?Account
    {
        return AccountMapping::forKeyOn('inventory.goods_received_not_invoiced', $on)?->account
            ?? Account::bySlug('goods_received_not_invoiced');
    }
}
