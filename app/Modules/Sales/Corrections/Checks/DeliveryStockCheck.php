<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A challan's goods can go back onto the layers they left (corrections wave
 * 1, 20 Oct 2026): each batch it took from still exists, at the cost the
 * challan took it at, and none of its issues was already put back.
 *
 * ⚠ The cost rule is the paisa rule of UndoDelivery: the restored movement is
 * valued at the batch's cost and the mirrored journal at the challan's, so a
 * batch revalued since (a landed cost, a supplier discount) would make 1150
 * and the batches disagree. Refused with a sentence the accountant can act on,
 * never "fixed" by guessing which cost is right (FINAL-PLAN 3.3 cost rule).
 *
 * Also asked by the sales invoice policy for a counter sale's Void sale, whose
 * challan goes back with it.
 */
class DeliveryStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if ($action !== DocumentAction::Reverse) {
            return null;
        }

        $delivery = match (true) {
            $document instanceof Delivery => $document,
            $document instanceof SalesInvoice => SalesInvoiceActionPolicy::voidBundle($document)?->delivery,
            default => null,
        };

        if ($delivery === null || $delivery->isReversed()) {
            return null;
        }

        $delivery->loadMissing('lines.movements');

        foreach ($delivery->lines as $line) {
            foreach ($line->movements as $movement) {
                /** @var StockMovement $movement */
                $batch = StockBatch::query()->find($movement->batch_id);

                if ($batch === null || bccomp((string) $batch->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                    return new Blocker(
                        'batch_cost_changed',
                        "A batch on challan {$delivery->delivery_number} changed its cost after the goods left, so the challan cannot be put back by itself; ask the accountant.",
                        resolvableBy: 'accountant',
                    );
                }

                if (StockMovement::query()->where('reverses_movement_id', $movement->id)->exists()) {
                    return new Blocker(
                        'already_restored',
                        "Goods from challan {$delivery->delivery_number} were already put back into stock, so it cannot be reversed again.",
                        resolvableBy: 'administrator',
                    );
                }
            }
        }

        return null;
    }
}
