<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One product's price for each customer group, as the owner enters it in the
 * product dialog (21 Sep 2026): a fixed taka price, a percentage off MRP, or
 * nothing.
 *
 * ⚠⚠ NOTHING HERE IS GENERATED. A group left at "nothing" gets no row, and the
 * pricing engine then refuses that sale (or charges MRP, for a group that falls
 * back to it). His words: "keep them null ... so I can not sell them until I add
 * the price for the products and to whom I wanna sell".
 *
 * ⚠ A change never edits the row in force: it closes it the day before and
 * writes a new one from today, the rule ResolveSellingPrice is built on, so a
 * price from July still explains a July invoice. Only the plain group price is
 * touched (no customer, no quantity break, not a discount): those are managed on
 * Sales > Selling prices and stay exactly as they are.
 *
 * ⚠ "% off MRP" is stored as the taka price it came to, with the percentage and
 * the MRP beside it (settled with ChatGPT): an MRP change never moves a price by
 * itself. The dialog re-works the figures from the new MRP and saving writes
 * them, visibly.
 */
class SetProductGroupPrices
{
    public const METHODS = ['none', 'fixed', 'percent_off_mrp'];

    /**
     * @param  array<int, array{method: string, value?: string|float|int|null}>  $entries  keyed by price_group_id
     * @return array{set: int, ended: int, unchanged: int}
     */
    public function handle(Product $product, array $entries, ?User $by = null, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $groups = PriceGroup::query()->where('business_id', $product->business_id)->get()->keyBy('id');
        $result = ['set' => 0, 'ended' => 0, 'unchanged' => 0];

        foreach (array_keys($entries) as $groupId) {
            if (! $groups->has($groupId)) {
                throw ValidationException::withMessages(['prices' => 'One of those price groups belongs to another business.']);
            }
        }

        DB::transaction(function () use ($product, $entries, $by, $today, $groups, &$result) {
            foreach ($entries as $groupId => $entry) {
                $method = $entry['method'] ?? 'none';

                if (! in_array($method, self::METHODS, true)) {
                    throw ValidationException::withMessages(["prices.{$groupId}.method" => 'Choose fixed price, % off MRP, or none.']);
                }

                $current = ProductPrice::query()
                    ->where('product_id', $product->id)
                    ->where('price_group_id', $groupId)
                    ->whereNull('customer_id')
                    ->where('min_quantity', 0)
                    ->where('is_discount', false)
                    ->inForceOn($today)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();

                if ($method === 'none') {
                    if ($current !== null) {
                        $this->close($current, $today);
                        $result['ended']++;
                    } else {
                        $result['unchanged']++;
                    }

                    continue;
                }

                [$price, $percent, $basis] = $this->priceFor($product, $groups[$groupId], $method, $entry['value'] ?? null, $groupId);

                if ($current !== null
                    && bccomp((string) $current->unit_price, $price, 4) === 0
                    && $current->price_method === $method
                    && bccomp((string) ($current->percent_off ?? '0'), $percent ?? '0', 4) === 0) {
                    $result['unchanged']++;

                    continue;
                }

                if ($current !== null) {
                    $this->close($current, $today);
                }

                ProductPrice::create([
                    'business_id' => $product->business_id,
                    'product_id' => $product->id,
                    'price_group_id' => $groupId,
                    'min_quantity' => '0',
                    'unit_price' => $price,
                    'price_method' => $method,
                    'percent_off' => $percent,
                    'basis_mrp' => $basis,
                    'effective_from' => $today->toDateString(),
                    'note' => 'Set in the product dialog',
                    'created_by' => $by?->id,
                ]);
                $result['set']++;
            }
        });

        return $result;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [unit price, percent off, the MRP it came from]
     */
    private function priceFor(Product $product, PriceGroup $group, string $method, mixed $value, int $groupId): array
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(["prices.{$groupId}.value" => "Enter the {$group->name} price, or choose none."]);
        }

        $value = (string) $value;

        if ($method === 'fixed') {
            if (bccomp($value, '0', 4) <= 0) {
                throw ValidationException::withMessages(["prices.{$groupId}.value" => "The {$group->name} price must be more than zero."]);
            }

            return [bcadd($value, '0', 4), null, $product->mrp === null ? null : (string) $product->mrp];
        }

        if ($product->mrp === null || bccomp((string) $product->mrp, '0', 4) <= 0) {
            throw ValidationException::withMessages(["prices.{$groupId}.value" => "Enter the MRP first: {$group->name} is a percentage off it."]);
        }

        if (bccomp($value, '0', 4) <= 0 || bccomp($value, '100', 4) >= 0) {
            throw ValidationException::withMessages(["prices.{$groupId}.value" => 'A percentage off MRP is more than 0 and less than 100.']);
        }

        // ⚠ Rounded to the paisa, half up, and nothing else: no "nearest 5 taka"
        // rule has been given, and inventing one would change every price.
        $mrp = (string) $product->mrp;
        $exact = bcdiv(bcmul($mrp, bcsub('100', $value, 6), 6), '100', 6);
        // Whole paisa: add half a paisa, then drop the rest (positive figures only).
        $paisa = bcdiv(bcadd(bcmul($exact, '100', 6), '0.5', 6), '1', 0);

        return [bcdiv($paisa, '100', 4), bcadd($value, '0', 4), bcadd($mrp, '0', 4)];
    }

    private function close(ProductPrice $row, Carbon $today): void
    {
        $row->effective_to = $today->copy()->subDay()->toDateString();
        $row->save();
    }
}
