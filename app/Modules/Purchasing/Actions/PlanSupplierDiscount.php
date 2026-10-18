<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Support\LandedCostPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out, without writing anything, which batches a supplier discount
 * comes off and how each share splits between stock on hand and stock already
 * consumed or sold (ruling P3, 18 Oct 2026).
 *
 *  1. ONTO THE BILL'S STOCK LINES: as typed per line, or the bill-level amount
 *     spread by each line's value (its line total). A line that put nothing
 *     into stock (a service) takes none of it: a discount on a service is not
 *     a discount on goods.
 *  2. ONTO THE RECEIPTS THE LINE INVOICED: a purchase order line received in
 *     several deliveries and billed in several bills is matched first in,
 *     first billed: earlier live bills on the line took the earlier receipts,
 *     this bill takes the next units. Within a line the share follows the
 *     units (one price per line).
 *  3. ONTO STOCK ON HAND AND CONSUMED: PlanLandedCostAllocation::planReduction,
 *     THE landed-cost split run the other way, so a discount and a late cost
 *     can never split one batch two ways.
 *
 * ⚠⚠ REFUSES WITH A SENTENCE when the goods cannot be identified: a line part
 * returned to the supplier, a batch transferred to another warehouse, a bill
 * with no stocked line, units the receipts do not cover. Never a default to
 * 5400 PPV or 4950: a discount that cannot be put on its goods is a question
 * for a person, not an account.
 *
 * ⚠ INTEGER PAISA throughout; the parts always sum to the whole.
 */
class PlanSupplierDiscount
{
    public function __construct(private readonly PlanLandedCostAllocation $engine) {}

    /**
     * @param  array<int, int>|null  $perLine  supplier_bill_line_id => paisa, when typed per line
     * @return array{allocations: list<array<string, mixed>>, on_hand: int, consumed: int, refusals: list<string>}
     */
    public function handle(SupplierBill $bill, int $amountPaisa, Carbon $on, ?array $perLine = null): array
    {
        $bill->loadMissing('lines.purchaseOrderLine');
        $label = 'Discount of '.Money::ofMinor($amountPaisa)->format().' on bill '.$bill->bill_number;
        $refusals = [];

        $receiptLinesOf = fn (SupplierBillLine $line) => $line->purchaseOrderLine === null ? collect() : GoodsReceiptLine::query()
            ->where('purchase_order_line_id', $line->purchase_order_line_id)
            ->whereNotNull('stock_movement_id')
            ->with(['goodsReceipt:id,received_at', 'stockMovement.batch', 'purchaseOrderLine.product:id,name'])
            ->get()
            ->sortBy([fn ($a, $b) => strcmp((string) $a->goodsReceipt?->received_at, (string) $b->goodsReceipt?->received_at), fn ($a, $b) => $a->id <=> $b->id])
            ->values();

        $stocked = $bill->lines->filter(fn (SupplierBillLine $line) => $receiptLinesOf($line)->isNotEmpty())->keyBy('id');

        if ($stocked->isEmpty()) {
            return $this->refused("{$label}: nothing on this bill went into stock, so there are no goods whose cost it can reduce. A discount on a service is not a purchase-price discount; ask Finance how to record it.");
        }

        // 1. Onto the bill's stock lines.
        if ($perLine !== null) {
            $perLine = array_filter($perLine, fn (int $paisa) => $paisa > 0);

            foreach (array_keys($perLine) as $lineId) {
                if (! $stocked->has($lineId)) {
                    $refusals[] = "{$label}: bill line #{$lineId} put nothing into stock, so a discount cannot be put on it.";
                }
            }

            if (array_sum($perLine) !== $amountPaisa) {
                $refusals[] = "{$label}: the lines add up to ".Money::ofMinor(array_sum($perLine))->format().', not the discount.';
            }

            $lineShares = $perLine;
        } else {
            $weights = $stocked->map(fn (SupplierBillLine $line) => (string) max(Money::ofTaka((string) $line->line_total)->minor, 0))->all();

            if (array_sum(array_map('intval', $weights)) === 0) {
                return $this->refused("{$label}: the bill's stock lines carry no value to spread it by.");
            }

            $lineShares = $this->engine->spreadByWeights($amountPaisa, $weights);
        }

        if ($refusals !== []) {
            return $this->refused(...$refusals);
        }

        // 2. Onto the receipts each line invoiced.
        $receiptShares = [];
        $receiptLines = collect();

        foreach ($lineShares as $lineId => $share) {
            if ($share === 0) {
                continue;
            }

            /** @var SupplierBillLine $line */
            $line = $stocked->get($lineId);
            [$portions, $problem] = $this->portions($bill, $line, $receiptLinesOf($line));

            if ($problem !== null) {
                $refusals[] = "{$label}: {$problem}";

                continue;
            }

            $split = $this->engine->spreadByWeights($share, array_map('strval', $portions));

            foreach ($split as $receiptLineId => $paisa) {
                $receiptShares[$receiptLineId] = ($receiptShares[$receiptLineId] ?? 0) + $paisa;
            }

            $receiptLines = $receiptLines->concat($receiptLinesOf($line)->whereIn('id', array_keys($portions)));
        }

        if ($refusals !== []) {
            return $this->refused(...$refusals);
        }

        // 3. Onto stock on hand and consumed: the landed-cost engine, reversed.
        [$allocations, $problems] = $this->engine->planReduction(
            array_filter($receiptShares, fn (int $paisa) => $paisa > 0),
            $receiptLines->unique('id')->values(),
            $on,
            $label,
        );

        $onHand = array_sum(array_column($allocations, 'on_hand_share_paisa'));
        $consumed = array_sum(array_column($allocations, 'consumed_share_paisa'));

        if (($limit = LandedCostPolicy::consumedRefusal(Money::ofMinor($consumed))) !== null) {
            $problems[] = $limit;
        }

        if (($closed = LandedCostPolicy::closedMonthRefusal($on)) !== null) {
            $problems[] = $closed;
        }

        return ['allocations' => $allocations, 'on_hand' => $onHand, 'consumed' => $consumed, 'refusals' => array_values(array_unique($problems))];
    }

    /**
     * The units of each receipt line this bill line invoiced, scaled by
     * 10,000, first in first billed.
     *
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines  in arrival order
     * @return array{0: array<int, int>, 1: ?string}
     */
    private function portions(SupplierBill $bill, SupplierBillLine $line, Collection $receiptLines): array
    {
        $poLine = $line->purchaseOrderLine;
        $product = $receiptLines->first()?->purchaseOrderLine?->product?->name ?? "line #{$line->id}";

        // ⚠ A part returned to the supplier leaves no way to say which units
        // this bill paid for: the return reverses specific receipts, the bill
        // does not name them. Refused rather than guessed.
        if ($this->scale((string) $poLine->quantity_returned) > 0) {
            return [[], "part of {$product} was returned to the supplier, so the units this bill paid for cannot be identified. Resolve it with Finance."];
        }

        $before = $this->scale((string) SupplierBillLine::query()
            ->where('purchase_order_line_id', $poLine->id)
            ->whereHas('bill', fn ($query) => $query->whereNull('reversed_at')->where('id', '<', $bill->id))
            ->sum('quantity'));
        $wanted = $this->scale((string) $line->quantity);
        $portions = [];

        foreach ($receiptLines as $receiptLine) {
            $received = $this->scale((string) $receiptLine->quantity_received);
            $skipped = min($before, $received);
            $before -= $skipped;
            $take = min($received - $skipped, $wanted);

            if ($take > 0) {
                $portions[$receiptLine->id] = $take;
                $wanted -= $take;
            }

            if ($wanted === 0) {
                break;
            }
        }

        if ($wanted > 0 || $portions === []) {
            return [[], "the receipts of {$product} do not cover what this bill invoiced, so its batches cannot be identified."];
        }

        return [$portions, null];
    }

    /** @return array{allocations: list<array<string, mixed>>, on_hand: int, consumed: int, refusals: list<string>} */
    private function refused(string ...$refusals): array
    {
        return ['allocations' => [], 'on_hand' => 0, 'consumed' => 0, 'refusals' => array_values($refusals)];
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }
}
