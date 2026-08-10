<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\ResolvedPrice;
use Illuminate\Support\Carbon;

/**
 * What this product costs this customer, in this quantity, on this date.
 *
 * ⚠⚠ THE MOST SPECIFIC RULE IN FORCE WINS, and specificity has exactly three
 * tiers: a price agreed with this customer beats a price for their group, which
 * beats the standard selling price. Within a tier, the highest quantity break
 * the line qualifies for wins.
 *
 * ⚠ This is the whole of the pricing engine, and it is deliberately the only
 * place that decides a price. Every future pricing feature Joy listed -
 * customer, distributor, promotional, quantity - is a ROW, not a branch in here.
 * The day this method grows an `if` for a kind of pricing is the day the table
 * stopped being the answer.
 *
 * ⚠ Reads identically to AccountMapping::resolve on purpose: a `find` that
 * returns null and a `resolve` that throws. Two dated resolution tables that
 * behave differently would be two things to remember.
 */
class ResolveSellingPrice
{
    /**
     * @throws NoSellingPriceException
     */
    public function resolve(
        Product $product,
        ?Customer $customer = null,
        string $quantity = '1',
        mixed $on = null,
    ): ResolvedPrice {
        $date = $this->asDate($on);
        $resolved = $this->find($product, $customer, $quantity, $date);

        if ($resolved === null) {
            throw NoSellingPriceException::for($product->id, $product->name, $date);
        }

        return $resolved;
    }

    public function find(
        Product $product,
        ?Customer $customer = null,
        string $quantity = '1',
        mixed $on = null,
    ): ?ResolvedPrice {
        $date = $this->asDate($on);
        $wanted = $this->scale($quantity);

        $candidates = ProductPrice::query()
            ->where('product_id', $product->id)
            ->inForceOn($date)
            ->get()
            ->filter(fn (ProductPrice $rule) => $this->applies($rule, $customer, $wanted));

        if ($candidates->isEmpty()) {
            return null;
        }

        // ⚠ Sorted in PHP rather than SQL, and with an explicit comparator. The
        // ordering key is derived - specificity is a match over two nullable
        // columns - and expressing it as a CASE expression would put the rule in
        // two places, one of which is a string no test can reach.
        $winner = $candidates
            ->sort(fn (ProductPrice $a, ProductPrice $b) => $this->rank($a) <=> $this->rank($b))
            ->last();

        return new ResolvedPrice((string) $winner->unit_price, $winner);
    }

    /**
     * ⚠ Highest wins, compared element by element. A later `effective_from`
     * beats an earlier one so that superseding a price is writing a new row,
     * never editing the old one - the same principle as AccountMapping, where
     * history is what lets an invoice from July still be explained in September.
     *
     * @return array<int, int>
     */
    private function rank(ProductPrice $rule): array
    {
        return [
            $rule->specificity(),
            $this->scale((string) $rule->min_quantity),
            $rule->effective_from->timestamp,
            $rule->id,
        ];
    }

    /**
     * ⚠ A rule for a DIFFERENT customer, or for a group this customer is not in,
     * does not apply - and neither does a quantity break the line does not
     * reach. A null customer (a price check with nobody at the counter yet) sees
     * only the default tier, which is the honest answer: their price is not
     * knowable until they are known.
     */
    private function applies(ProductPrice $rule, ?Customer $customer, int $wantedQuantity): bool
    {
        if ($this->scale((string) $rule->min_quantity) > $wantedQuantity) {
            return false;
        }

        if ($rule->customer_id !== null) {
            return $customer !== null && $rule->customer_id === $customer->id;
        }

        if ($rule->price_group_id !== null) {
            return $customer !== null
                && $this->groupIdFor($customer) === $rule->price_group_id;
        }

        return true;
    }

    /**
     * The group this customer is priced in.
     *
     * ⚠⚠ RETAIL IS THE DEFAULT GROUP (D-PRC-2, Joy, 2026-08-10): a customer
     * with no group is priced as RETAIL. This mapping lives HERE and nowhere
     * else - a second place deciding what "no group" means would drift from
     * this one, and the two answers would differ exactly when a RETAIL price
     * row exists.
     *
     * ⚠ Looked up by the customer's OWN business_id, bypassing the global
     * scope, so a resolve running outside the customer's tenant context (a
     * queued job, a report) still answers for the right business. Cached per
     * business for the life of this action instance - one sale resolves a
     * price per line, and asking the same question per line is a query the
     * database does not need.
     *
     * ℹ A business whose RETAIL group is missing gets null, and the customer
     * simply falls through to the general tier - the pre-group behaviour,
     * degraded gracefully rather than an exception at the counter.
     *
     * @var array<int, int|null>
     */
    private array $defaultGroupIds = [];

    private function groupIdFor(Customer $customer): ?int
    {
        if ($customer->price_group_id !== null) {
            return $customer->price_group_id;
        }

        if (! array_key_exists($customer->business_id, $this->defaultGroupIds)) {
            $this->defaultGroupIds[$customer->business_id] = PriceGroup::query()
                ->withoutGlobalScopes()
                ->where('business_id', $customer->business_id)
                ->where('code', PriceGroup::DEFAULT_CODE)
                ->value('id');
        }

        return $this->defaultGroupIds[$customer->business_id];
    }

    /**
     * ⚠⚠ DELIBERATELY NOT EffectiveDate::resolve. That helper refuses a future
     * date and asserts the accounting period is open, which is exactly right for
     * moving stock and exactly wrong here: asking what a product will cost
     * during next month's promotion is a question, not an event, and it must not
     * be refused because the month has not opened yet.
     */
    private function asDate(mixed $on): Carbon
    {
        if ($on instanceof Carbon) {
            return $on;
        }

        return $on === null ? Carbon::now() : Carbon::parse($on);
    }

    /**
     * ⚠ Integer-scaled, not float. Quantities are decimal(15,4), so four places
     * are the entire domain and scaling by 10000 makes every comparison exact.
     * Identical to ConsumesFifoStock so that two places comparing the same kind
     * of number read the same way.
     *
     * ℹ Stated honestly, because this project has been bitten by a comment
     * claiming more than its test proves: no live float defect is known here,
     * and the guard in SellingPriceResolverTest catches LOST PRECISION (a break
     * at 2.5 truncated to 2) rather than float drift specifically. A plain float
     * comparison would pass that test. This is defensive consistency with the
     * FIFO engine, not the fix for an observed bug - which is exactly the
     * correction ConsumesFifoStock had to make about its own comment.
     */
    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }
}
