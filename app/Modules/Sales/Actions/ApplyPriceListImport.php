<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\PriceImportPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a plan that has already been reviewed.
 *
 * ⚠⚠ ONE TRANSACTION. A partially applied price list is the worst outcome
 * available: the products that imported would sell and the ones that did not
 * would throw at the counter, with nothing on screen to say which was which.
 *
 * ⚠ A plan with errors is refused here as well as in the command. The guard
 * belongs with the write, not only with the caller who happens to be a console
 * command today and could be a controller tomorrow.
 */
class ApplyPriceListImport
{
    /**
     * @return array{created:int, superseded:int}
     */
    public function handle(PriceImportPlan $plan, Carbon $effectiveFrom, User $by): array
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('A price plan carrying errors is never applied.');
        }

        return DB::transaction(function () use ($plan, $effectiveFrom, $by) {
            // ⚠⚠ CLOSE FIRST, THEN CREATE. If the new row were written first,
            // there would be an instant in the transaction with two rules in
            // force for the same product, group and quantity - and if the close
            // then failed, the resolver would pick between them by id forever.
            foreach ($plan->supersedes as $supersede) {
                $closesOn = Carbon::parse($supersede['closesOn'])->startOfDay();

                $rule = ProductPrice::query()->findOrFail($supersede['id']);

                // ⚠ A rule that started ON the new effective date cannot be
                // closed the day BEFORE it started. That is a same-day price
                // correction, and the honest record is a rule that was in force
                // for zero days rather than one with a negative life.
                if ($closesOn->lt($rule->effective_from)) {
                    $closesOn = $rule->effective_from->copy();
                }

                $rule->update(['effective_to' => $closesOn]);
            }

            foreach ($plan->creates as $create) {
                ProductPrice::create([
                    'product_id' => $create['productId'],
                    'price_group_id' => $create['priceGroupId'],
                    'customer_id' => null,
                    'min_quantity' => $create['minQuantity'],
                    'unit_price' => $create['price'],
                    'effective_from' => $effectiveFrom->copy()->startOfDay(),
                    'effective_to' => null,
                    'note' => 'Imported from price list',
                    'created_by' => $by->id,
                ]);
            }

            // The MRP is a product field, overwritten in place (29 Sep 2026).
            foreach ($plan->mrpChanges as $change) {
                Product::query()->whereKey($change['productId'])->update(['mrp' => $change['becomes']]);
            }

            return [
                'created' => count($plan->creates),
                'superseded' => count($plan->supersedes),
            ];
        });
    }
}
