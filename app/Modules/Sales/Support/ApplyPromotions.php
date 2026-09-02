<?php

namespace App\Modules\Sales\Support;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Buy X Get Y free, decided on the SERVER at the moment of sale. The screen
 * may hint, but the free line is never something a client gets to claim.
 *
 * The free line carries an explicit unit price of zero: revenue 0, cost of
 * goods still posts, stock still moves - the books tell the truth about
 * giving stock away, which is what a promotion is.
 */
class ApplyPromotions
{
    /**
     * Extra sale lines the cart has earned, in the shape RecordCounterSale
     * takes. Whole multiples only: buy 3 get 1 means 7 bought earns 2.
     *
     * @param  list<array{product: Product, quantity: string}>  $lines
     * @return list<array{product: Product, quantity: string, unitPrice: string, discount: string}>
     */
    public function earnedLines(array $lines, ?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::now())->toDateString();

        $promotions = DB::table('promotions')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->get();

        if ($promotions->isEmpty()) {
            return [];
        }

        $boughtByProduct = [];
        foreach ($lines as $entry) {
            $id = $entry['product']->id;
            $boughtByProduct[$id] = ($boughtByProduct[$id] ?? 0) + (float) $entry['quantity'];
        }

        $earned = [];
        foreach ($promotions as $promotion) {
            $bought = $boughtByProduct[$promotion->buy_product_id] ?? 0;
            $times = (int) floor($bought / (float) $promotion->buy_quantity);

            if ($times < 1) {
                continue;
            }

            $product = Product::find($promotion->get_product_id);
            if ($product === null || ! $product->is_active) {
                continue;
            }

            $earned[] = [
                'product' => $product,
                'quantity' => (string) ($times * (float) $promotion->get_quantity),
                'unitPrice' => '0',
                'discount' => '0',
            ];
        }

        return $earned;
    }
}
