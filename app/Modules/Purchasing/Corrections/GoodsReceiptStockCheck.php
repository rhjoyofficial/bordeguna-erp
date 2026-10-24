<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Inventory\Support\SoldStockShare;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ The goods a receipt brought in, and what became of them (corrections wave
 * 1, 19 Oct 2026; FINAL-PLAN 3.3 "Reverse: goods untouched. Sold part: cost
 * rule below").
 *
 * Untouched goods simply go back out. ⭐ 24 Oct 2026 (the Tk 10,000 rule,
 * SoldStock): goods partly sold or used no longer refuse Reverse. What is left
 * goes back out on the receipt's own date; the sales keep the cost they went
 * out at; the difference posts today to cost of sales (up to Tk 10,000 by
 * anyone who may correct, above it the Owner: GoodsReceiptActionPolicy).
 * Refused only where it is really unsafe, in SoldStock's sentence: reserved
 * for an order, moved to another warehouse, sent back to the supplier, its
 * cost changed since, or a history that would turn negative.
 *
 * ⚠ Only REVERSE is decided here. A Correct that changes only the date keeps
 * the same batch and is allowed with part of it sold (GoodsReceiptCorrector
 * checks the one thing that can break: a withdrawal before the new date). A
 * Correct of the quantity asks `shares()` itself (same batch when used).
 */
class GoodsReceiptStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if ($action !== DocumentAction::Reverse || ! $document instanceof GoodsReceipt || $document->isReversed()) {
            return null;
        }

        foreach (self::shares($document) as $share) {
            if ($share->refusal !== null) {
                return new Blocker('stock_moved', $share->refusal, resolvableBy: 'finance');
            }
        }

        return null;
    }

    /**
     * Per receipt line with a batch: what is left and what was used.
     *
     * @return array<int, SoldStockShare> keyed by receipt line id
     */
    public static function shares(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing('lines.stockMovement');
        $shares = [];

        foreach ($receipt->lines as $line) {
            /** @var GoodsReceiptLine $line */
            if ($line->stockMovement !== null && ($share = SoldStock::share($line->stockMovement)) !== null) {
                $shares[(int) $line->id] = $share;
            }
        }

        return $shares;
    }

    /** What the goods already sold or used cost when they came in, in paisa. */
    public static function usedCostMinor(GoodsReceipt $receipt): int
    {
        return array_sum(array_map(fn (SoldStockShare $share) => $share->costMinor, self::shares($receipt)));
    }

    /**
     * Null while every batch is untouched; otherwise the one sentence why not.
     * Kept for the quantity-or-lot Correct of an UNUSED receipt (a new lot).
     */
    public static function touched(GoodsReceipt $receipt): ?string
    {
        $receipt->loadMissing('lines.stockMovement.batch');

        foreach ($receipt->lines as $line) {
            /** @var GoodsReceiptLine $line */
            $movement = $line->stockMovement;

            if ($movement === null || $movement->batch === null) {
                continue;
            }

            $batch = $movement->batch;
            $name = $batch->batch_number ?? "#{$batch->id}";

            $moved = StockMovement::query()
                ->where('batch_id', $batch->id)
                ->whereKeyNot($movement->id)
                ->where('quantity', '!=', 0)
                ->exists();

            $reserved = StockReservation::query()
                ->where('stock_batch_id', $batch->id)
                ->whereNull('released_at')
                ->exists();

            if ($moved || $reserved || bccomp((string) $batch->current_quantity, (string) $movement->quantity, 4) !== 0) {
                return "Goods from batch {$name} have been used, sold, moved or reserved since, so this receipt cannot simply be reversed. Correct only its date, or ask Finance about a stock correction.";
            }
        }

        return null;
    }
}
