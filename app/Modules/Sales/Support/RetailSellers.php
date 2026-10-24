<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\PriceGroup;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ Initial plan B21 (R109, R106; Joy's Q3 = A and Q13 = A, 24 Oct 2026):
 * what the retail seller screens and actions share.
 *
 * ⚠ THE TRADE PRICE GROUP IS FOUND BY ITS CODE, never its label. Joy's Q13
 * renames "Retail shop" to "Trade Price (TP)" on another branch; whether the
 * code becomes `TP` or stays `SHOP`, both are recognised here, `TP` first.
 */
final class RetailSellers
{
    /** The codes the Trade Price group may carry, in order of preference. */
    public const TRADE_PRICE_CODES = ['TP', PriceGroup::SHOP_CODE];

    public static function tradePriceGroup(): ?PriceGroup
    {
        foreach (self::TRADE_PRICE_CODES as $code) {
            $group = PriceGroup::query()->where('code', $code)->first();

            if ($group !== null) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The Trade Price of a product, or null when none is set.
     *
     * ⚠⚠ Asked with NO customer and the group named (ResolveSellingPrice
     * reads a named group only when nobody is known; a walk-in would be read
     * at his own RETAIL group). Read that way the resolver also offers the
     * standard tier and the MRP, so only an answer from a row OF THE TRADE
     * PRICE GROUP counts: a product with no Trade Price has none, it is never
     * silently handed over at MRP.
     */
    public static function tradePrice(Product $product, string $quantity = '1', mixed $on = null, ?ResolvedPrice $found = null): ?string
    {
        $group = self::tradePriceGroup();

        if ($group === null) {
            return null;
        }

        $found ??= app(ResolveSellingPrice::class)->find($product, null, $quantity, $on, $group->id);

        return $found !== null && ! $found->isMrp() && ! $found->isBatchCost() && (int) $found->rule->price_group_id === (int) $group->id
            ? (string) $found->unitPrice
            : null;
    }

    /**
     * Trade Prices for a whole list in one query, keyed by product id.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, string>
     */
    public static function tradePrices($products, mixed $on = null): array
    {
        $group = self::tradePriceGroup();

        if ($group === null) {
            return [];
        }

        $prices = [];

        foreach (app(ResolveSellingPrice::class)->findMany($products, null, '1', $on, $group->id) as $id => $found) {
            $product = $products->firstWhere('id', $id);
            $price = $product === null ? null : self::tradePrice($product, found: $found);

            if ($price !== null) {
                $prices[$id] = $price;
            }
        }

        return $prices;
    }

    /** The seller stock of this employee, if he was ever made a seller. */
    public static function stockOf(Employee|int $employee): ?Warehouse
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return Warehouse::query()->where('seller_employee_id', $id)->first();
    }

    /** Every seller stock of the business in context. */
    public static function stocks(): Builder
    {
        return Warehouse::query()->whereNotNull('seller_employee_id');
    }

    /** His own Employee cash account(s) (Joy's Q1), active ones first. */
    public static function cashAccountsOf(int $employeeId): Builder
    {
        return MoneyAccount::query()
            ->where('type', MoneyAccountType::EmployeeCash->value)
            ->where('custodian_employee_id', $employeeId)
            ->orderByDesc('is_active')
            ->orderBy('id');
    }

    /**
     * The next number for a seller document: SH (hand-over), SR (return),
     * SS (reported sale), per fiscal year, as every other document.
     */
    public static function nextNumber(Builder $query, string $prefix, Carbon $date, int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);

        return DocumentNumber::next($query, 'number', $prefix.'-'.FiscalYear::containing($date, $business)->label().'-');
    }
}
