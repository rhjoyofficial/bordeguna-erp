<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderCharge;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\RefusalMessage;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ The order's charges become the delivery's landed cost (purchase redesign
 * PR1, 18 Oct 2026; Tally's additional cost of purchase, Joy: "product cost
 * must come from all its related costs"). Called by RecordGoodsReceipt, in
 * its transaction, for every receipt of an order that has charges.
 *
 * ⚠⚠ NO DOUBLE COUNT. Each charge gives this delivery its SHARE and remembers
 * it (`landed_paisa`): by the value received over the order's value, or for a
 * one-item charge by that item's quantity; the delivery that completes the
 * order (or the item) takes exactly what is left. A charge already fully
 * landed gives nothing, however many receipts follow.
 *
 * ⚠ The ORDINARY landed-cost engine does the rest: SaveLandedCost writes the
 * draft (LC-...), SubmitLandedCost plans and sends it (approved on the spot
 * for whoever may post landed costs, as everywhere), PostLandedCost puts it
 * into the batches. The receiver needs `purchasing.landed_cost.manage` for
 * the draft to be sent; without it the draft waits on the landed-cost screen
 * for someone who may.
 *
 * ⚠ It NEVER refuses the receipt. Goods that physically arrived stay received;
 * a send or post that is refused (a product with no weight for a weight
 * split, a missing ledger mapping) leaves the draft as it is, and `notes`
 * says why, for the screen to show.
 */
class RaiseOrderCharges
{
    /** @var array<int, list<string>> receipt id => what to tell the receiver */
    private static array $notes = [];

    /** Buy and receive: a refusal refuses the whole purchase (all or nothing). */
    private static bool $strict = false;

    /**
     * Runs `$step` with refusals passed on instead of left as notes.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public static function strictly(callable $step): mixed
    {
        $before = self::$strict;
        self::$strict = true;

        try {
            return $step();
        } finally {
            self::$strict = $before;
        }
    }

    public function __construct(
        private readonly SaveLandedCost $save,
        private readonly SubmitLandedCost $submit,
        private readonly PostLandedCost $post,
    ) {}

    /** What the last receipt's charges need the receiver to know, if anything. */
    public static function notesFor(int $receiptId): array
    {
        return self::$notes[$receiptId] ?? [];
    }

    /**
     * @return array{landed_cost: ?LandedCost, notes: list<string>}
     */
    public function handle(GoodsReceipt $receipt, User $by): array
    {
        $order = PurchaseOrder::query()->with(['charges', 'lines'])->find($receipt->purchase_order_id);

        if ($order === null || $order->charges->isEmpty()) {
            return ['landed_cost' => null, 'notes' => []];
        }

        $receipt->loadMissing('lines');
        $plan = $this->shares($order, $receipt);

        if ($plan === []) {
            return ['landed_cost' => null, 'notes' => []];
        }

        $notes = [];
        $landedCost = null;

        try {
            $landedCost = DB::transaction(fn () => $this->save->handle(
                receipt: $receipt,
                incurredOn: $receipt->received_at ?? now(),
                lines: array_map(fn (array $row) => $row['line'], $plan),
                actor: $by,
                note: "Charges on {$order->po_number}, carried from the order",
            ));

            foreach ($plan as $row) {
                PurchaseOrderCharge::query()->whereKey($row['charge']->id)->increment('landed_paisa', $row['share']);
            }
        } catch (ValidationException $e) {
            if (self::$strict) {
                throw $e;
            }

            $notes[] = "The charges on {$order->po_number} could not become a landed cost: ".collect($e->errors())->flatten()->first().' Add them on the Landed costs screen.';
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            if (self::$strict) {
                throw $e;
            }

            $notes[] = "The charges on {$order->po_number} could not become a landed cost: ".RefusalMessage::of($e).' Add them on the Landed costs screen.';
        }

        if ($landedCost !== null) {
            $landedCost = $this->sendAndPost($landedCost, $by, $notes);
        }

        self::$notes[$receipt->id] = $notes;

        return ['landed_cost' => $landedCost, 'notes' => $notes];
    }

    /**
     * Sent and, once approved, posted, each in its own savepoint so a refusal
     * leaves the draft standing.
     *
     * @param  list<string>  $notes
     */
    private function sendAndPost(LandedCost $landedCost, User $by, array &$notes): LandedCost
    {
        if (! $by->can('purchasing.landed_cost.manage')) {
            $notes[] = "Landed cost {$landedCost->number} is saved as a draft with the order's charges; someone who may enter landed costs sends it.";

            return $landedCost;
        }

        try {
            $landedCost = DB::transaction(fn () => $this->submit->handle($landedCost, $by));
        } catch (RuntimeException|InvalidArgumentException|DomainException|ValidationException $e) {
            if (self::$strict) {
                throw $e;
            }

            $notes[] = "Landed cost {$landedCost->number} is saved as a draft: ".RefusalMessage::of($e);

            return $landedCost->fresh();
        }

        if ($landedCost->status !== LandedCostStatus::Approved) {
            $notes[] = "Landed cost {$landedCost->number} waits for approval; the charges reach the item cost when it is approved and posted.";

            return $landedCost;
        }

        try {
            return DB::transaction(fn () => $this->post->handle($landedCost, $by));
        } catch (RuntimeException|InvalidArgumentException|DomainException|ValidationException $e) {
            if (self::$strict) {
                throw $e;
            }

            $notes[] = "Landed cost {$landedCost->number} is approved but not posted: ".RefusalMessage::of($e);

            return $landedCost->fresh();
        }
    }

    /**
     * Each charge's share for this delivery, as a landed-cost line.
     *
     * @return list<array{charge: PurchaseOrderCharge, share: int, line: array<string, mixed>}>
     */
    private function shares(PurchaseOrder $order, GoodsReceipt $receipt): array
    {
        /** @var array<int, GoodsReceiptLine> $byOrderLine */
        $byOrderLine = $receipt->lines->keyBy('purchase_order_line_id')->all();
        $orderValue = $order->lines->sum(fn (PurchaseOrderLine $line) => $line->lineTotal()->minor);
        $receiptValue = 0;

        foreach ($receipt->lines as $receiptLine) {
            $orderLine = $order->lines->firstWhere('id', $receiptLine->purchase_order_line_id);
            $receiptValue += $orderLine?->valueOf((string) $receiptLine->quantity_received)->minor ?? 0;
        }

        // The delivery that leaves nothing outstanding takes what is left.
        $complete = $order->lines->every(fn (PurchaseOrderLine $line) => PurchaseLineEntry::scaled((string) $line->quantity_received) >= PurchaseLineEntry::scaled((string) $line->quantity_ordered));
        $plan = [];

        foreach ($order->charges as $charge) {
            $remaining = $charge->remaining()->minor;

            if ($remaining <= 0) {
                continue;
            }

            $target = null;

            if ($charge->allocation_method === LandedCostAllocationMethod::Direct) {
                $orderLine = $order->lines->firstWhere('id', $charge->purchase_order_line_id);
                $receiptLine = $byOrderLine[$charge->purchase_order_line_id] ?? null;

                if ($orderLine === null || $receiptLine === null) {
                    continue;
                }

                $lineDone = PurchaseLineEntry::scaled((string) $orderLine->quantity_received) >= PurchaseLineEntry::scaled((string) $orderLine->quantity_ordered);
                $share = $lineDone ? $remaining : min($remaining, PurchaseLineEntry::shareOf($charge->money(), (string) $receiptLine->quantity_received, (string) $orderLine->quantity_ordered)->minor);
                $target = $receiptLine->id;
            } else {
                $share = $complete || $orderValue <= 0
                    ? $remaining
                    : min($remaining, intdiv((int) $charge->amount_paisa * $receiptValue * 2 + $orderValue, 2 * $orderValue));
            }

            if ($share <= 0) {
                continue;
            }

            $plan[] = [
                'charge' => $charge,
                'share' => $share,
                'line' => [
                    'cost_type' => $charge->cost_type->value,
                    'treatment' => $charge->treatment?->value,
                    'override_reason' => $charge->override_reason,
                    'allocation_method' => $charge->allocation_method->value,
                    'goods_receipt_line_id' => $target,
                    'amount' => Money::ofMinor($share)->toTaka(),
                    'supplier_id' => $charge->supplier_id,
                    'money_account_id' => $charge->money_account_id,
                    'expense_account_id' => $charge->expense_account_id,
                    'description' => $charge->description,
                ],
            ];
        }

        return $plan;
    }
}
