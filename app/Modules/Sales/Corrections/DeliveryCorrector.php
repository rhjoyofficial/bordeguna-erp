<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Actions\Corrections\UndoDelivery;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ A challan's own reversal (corrections wave 1, 20 Oct 2026): UndoDelivery
 * does the work (stock back onto its layers, the GDNI journal mirrored, the
 * quantity back on the order); this corrector is the engine's handle on it.
 * Correct is not offered, so there is no successor.
 */
class DeliveryCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly DeliveryActionPolicy $policy,
        private readonly UndoDelivery $undo,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return Delivery::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $delivery = Delivery::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        DeliveryLine::query()->where('delivery_id', $delivery->id)->lockForUpdate()->get(['id']);
        Journal::query()->where('source_type', $delivery->getMorphClass())->where('source_id', $delivery->getKey())->lockForUpdate()->get(['id']);

        return $delivery->load('lines.movements');
    }

    public function fingerprint(Model $document): string
    {
        /** @var Delivery $document */
        $lock = AccountingPeriod::current();
        $lines = DeliveryLine::query()->where('delivery_id', $document->id)->orderBy('id')
            ->get(['id', 'sales_order_line_id', 'quantity', 'quantity_invoiced', 'quantity_returned', 'quantity_returned_uninvoiced']);

        return hash('sha256', (string) json_encode([
            'd' => [$document->id, $document->delivery_number, $document->delivered_at?->toIso8601String(), $document->warehouse_id, $document->reversed_at?->toIso8601String()],
            'lines' => $lines->map(fn ($l) => [$l->id, $l->sales_order_line_id, (string) $l->quantity, (string) $l->quantity_invoiced, (string) $l->quantity_returned, (string) $l->quantity_returned_uninvoiced])->all(),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())->orderBy('id')->pluck('id')->all(),
            'parcel' => Consignment::query()->where('delivery_id', $document->id)->get(['id', 'status', 'courier_status'])->map(fn ($c) => [$c->id, $c->status?->value, $c->courier_status?->value])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A challan is not corrected in place. Reverse it and raise the right one from the order.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var Delivery $document */
        $document->loadMissing(['lines.product:id,name', 'lines.movements', 'warehouse:id,name']);
        $on = Tk::date($dates->reversalDate);
        $cost = $document->costValue()->minor;
        $warehouse = $document->warehouse?->name ?? 'the warehouse';
        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = new EffectLine(self::quantity((string) $line->quantity).' of '.($line->product?->name ?? 'a product')." go back into {$warehouse}, on the same batches.", null, null, 'stock');
        }

        if ($cost > 0) {
            $lines[] = new EffectLine(Tk::of($cost)." of stock comes back out of Goods Delivered Not Invoiced on {$on}.", 'Goods Delivered Not Invoiced', -$cost, 'ledger');
        }

        $orders = SalesOrder::query()->whereIn('id', SalesOrderLine::query()->whereIn('id', $document->lines->pluck('sales_order_line_id')->filter())->select('sales_order_id'))->pluck('order_number');

        foreach ($orders as $number) {
            $lines[] = new EffectLine("The quantities go back on order {$number}, to be delivered again.", null, null, 'allocation');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->delivery_number} stays, marked Reversed.", 'Nothing is deleted: the stock movements and the journal keep their history.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->delivered_at->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return CustomerReceiptCorrector::makerAndLastEditor($document);
    }

    public function journalOf(Model $document): ?Journal
    {
        return SalesLedgerCheck::journalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var Delivery $document */
        try {
            return $this->undo->undo($document, $ctx->actor, $ctx->dates->reversalDate, "Reversal of challan {$document->delivery_number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A challan has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->undo->mark($document, $ctx->actor, $ctx->dates->reversalDate, $ctx->reason->display(), $reversal);
    }

    public function successorClass(): string
    {
        return Delivery::class;
    }

    /**
     * This type's own invariants: marked; every layer it took was put back
     * once, in full, at the same cost; no order line delivered below zero.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var Delivery $original */
        if (! $original->isReversed()) {
            throw new LogicException("Challan {$original->delivery_number} was not marked.");
        }

        foreach ($original->load('lines.movements')->lines as $line) {
            foreach ($line->movements as $movement) {
                $back = StockMovement::query()->where('reverses_movement_id', $movement->id)->get(['quantity', 'unit_cost']);

                if ($back->count() !== 1 || bccomp((string) $back->first()->quantity, (string) $movement->quantity, 4) !== 0
                    || bccomp((string) $back->first()->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                    throw new LogicException("Challan {$original->delivery_number}: a layer was not put back exactly once.");
                }
            }

            if ($line->sales_order_line_id !== null && (float) SalesOrderLine::query()->whereKey($line->sales_order_line_id)->value('quantity_delivered') < -0.00005) {
                throw new LogicException("Challan {$original->delivery_number}: an order line went below nothing delivered.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    private static function quantity(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
