<?php

namespace App\Modules\Sales\Actions\Sellers;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\SellerStockGate;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Support\RetailSellers;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B21 (R109; Joy's Q3 = A, 24 Oct 2026): goods handed over to a
 * retail seller, or his unsold goods taken back.
 *
 *   handover   from the chosen warehouse into his seller stock; every line is
 *              priced at the Trade Price for the sheet he signs and keeps
 *              ("company gave them a invoice also keep to company with price
 *              Trade Price"). A product with no Trade Price is refused: the
 *              paper would say nothing about what he owes for it.
 *   return     from his seller stock back to the chosen warehouse.
 *
 * ⚠⚠ NEITHER IS A SALE. Each line is a stock transfer dispatched and received
 * in the same instant (DispatchStockTransfer, ReceiveStockTransfer): the
 * batch, its cost and its expiry travel with the jars, and no journal is
 * written. Revenue and cost of goods sold come only from his reported sales.
 *
 * ⚠ All or nothing, in one transaction; the sheet's number is claimed inside
 * it. A second click of the same form finds the employee's row locked and
 * the stock already gone.
 */
class MoveSellerStock
{
    public function __construct(
        private readonly DispatchStockTransfer $dispatch,
        private readonly ReceiveStockTransfer $receive,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: string}>  $lines
     */
    public function handle(
        string $kind,
        Employee $seller,
        Warehouse $other,
        array $lines,
        User $by,
        mixed $movedOn,
        ?string $reason = null,
        ?string $note = null,
    ): SellerTransfer {
        $stock = RetailSellers::stockOf($seller);

        if ($stock === null) {
            throw ValidationException::withMessages(['employee_id' => "{$seller->fullName()} is not a retail seller yet. Make him one first."]);
        }

        if (! in_array($kind, [SellerTransfer::KIND_HANDOVER, SellerTransfer::KIND_RETURN], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a hand-over or a return.']);
        }

        if ($other->isSellerStock() || ! $other->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose an active warehouse of the company, not a seller stock.']);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => (float) $line['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item with a quantity above zero.']);
        }

        $at = EffectiveDate::resolveConsumingDay($movedOn, 'moved_on');
        $handover = $kind === SellerTransfer::KIND_HANDOVER;
        if ($handover && RetailSellers::tradePriceGroup() === null) {
            throw ValidationException::withMessages(['lines' => 'There is no Trade Price group to price the sheet. Add it under Sales, Price groups.']);
        }

        // ⚠ Priced before anything moves, every refusal at once.
        $priced = [];
        $refusals = [];

        foreach ($lines as $index => $line) {
            $found = RetailSellers::tradePrice($line['product'], (string) $line['quantity'], $at);

            if ($found === null && $handover) {
                $refusals["lines.{$index}.product_id"] = "{$line['product']->name} has no Trade Price. Set it under Sales, Selling prices first.";
            }

            $priced[] = $line + ['trade_price' => $found ?? '0'];
        }

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        [$source, $target] = $handover ? [$other, $stock] : [$stock, $other];
        $transferReason = Reason::query()->firstOrCreate(
            ['business_id' => app(BusinessContext::class)->id(), 'context' => 'inventory.stock_transfer', 'name' => 'Retail seller stock'],
            ['is_active' => true],
        );

        return SellerStockGate::through(fn () => DB::transaction(function () use ($kind, $seller, $stock, $other, $source, $target, $priced, $by, $at, $reason, $note, $transferReason, $handover) {
            Employee::query()->whereKey($seller->id)->lockForUpdate()->first();

            $sheet = DocumentNumber::claim(
                'number',
                fn () => RetailSellers::nextNumber(SellerTransfer::query(), $handover ? 'SH' : 'SR', $at, (int) app(BusinessContext::class)->id()),
                fn (string $number) => SellerTransfer::create([
                    'business_id' => app(BusinessContext::class)->id(),
                    'number' => $number,
                    'kind' => $kind,
                    'employee_id' => $seller->id,
                    'seller_warehouse_id' => $stock->id,
                    'other_warehouse_id' => $other->id,
                    'moved_on' => $at->toDateString(),
                    'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                    'note' => $note,
                    'created_by' => $by->id,
                ]),
            );

            $tradeTotal = 0.0;
            $costTotal = 0.0;
            $label = ($handover ? 'Hand-over ' : 'Seller return ').$sheet->number.' ('.$seller->fullName().')';

            foreach ($priced as $line) {
                $transfer = $this->dispatch->handle(
                    product: $line['product'],
                    source: $source,
                    target: $target,
                    quantity: (string) $line['quantity'],
                    reason: $transferReason,
                    performedBy: $by,
                    note: $label,
                    occurredAt: $at,
                );
                $this->receive->handle($transfer, $by, $at);

                $cost = (float) StockMovement::query()
                    ->where('transfer_group_id', $transfer->transfer_group_id)
                    ->where('direction', 'out')
                    ->get(['quantity', 'unit_cost'])
                    ->sum(fn (StockMovement $m) => (float) $m->quantity * (float) $m->unit_cost);

                $sheet->lines()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => (string) $line['quantity'],
                    'trade_price' => $line['trade_price'],
                    'cost_value' => round($cost, 4),
                    'stock_transfer_id' => $transfer->id,
                ]);

                $tradeTotal += (float) $line['quantity'] * (float) $line['trade_price'];
                $costTotal += $cost;
            }

            $sheet->update(['trade_value' => round($tradeTotal, 2), 'cost_value' => round($costTotal, 4)]);

            return $sheet->fresh(['lines.product']);
        }));
    }
}
