<?php

namespace App\Support\Dashboards;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Owner dashboard's "Getting started" checklist (Joy, 11 Oct 2026): the
 * books are empty until the opening figures are in, so every money card reads
 * Tk 0. This says what is still missing, in the order it is usually done.
 *
 * Read from the real tables, per business, with one cheap query per step and a
 * short cache, so the dashboard never waits on it. Shown only to see-all
 * people (dashboard.view_all), only on the Owner dashboard, and never once
 * every step is done. A person may dismiss it; that choice is theirs alone
 * (users.preferences, per business) and changes nothing for anybody else.
 *
 * ⚠ Raw queries filter business_id by hand: the result is cached per business
 * and must never depend on which business happens to be in context.
 */
final class GettingStarted
{
    public const PREFERENCE = 'dashboard_getting_started_dismissed';

    public const CACHE_SECONDS = 60;

    /** @return list<array{key: string, done: bool, count: int|null, of: int|null, url: string}> */
    public static function steps(int $businessId): array
    {
        return Cache::remember(self::cacheKey($businessId), self::CACHE_SECONDS, fn () => self::compute($businessId));
    }

    public static function forget(int $businessId): void
    {
        Cache::forget(self::cacheKey($businessId));
    }

    /**
     * The prop the dashboard receives: null when the card is not for this
     * person, dismissed, or has nothing left to say.
     *
     * @return array{steps: list<array<string, mixed>>, done: int, total: int}|null
     */
    public static function forPage(User $user, int $businessId, DashboardKey $dashboard, bool $viewAll): ?array
    {
        if (! $viewAll || $dashboard !== DashboardKey::Owner || self::dismissed($user, $businessId)) {
            return null;
        }

        $steps = self::steps($businessId);
        $done = count(array_filter($steps, fn (array $step) => $step['done']));

        if ($done === count($steps)) {
            return null;
        }

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    public static function dismissed(User $user, int $businessId): bool
    {
        return in_array($businessId, array_map('intval', (array) (($user->preferences ?? [])[self::PREFERENCE] ?? [])), true);
    }

    public static function dismiss(User $user, int $businessId): void
    {
        $preferences = $user->preferences ?? [];
        $ids = array_map('intval', (array) ($preferences[self::PREFERENCE] ?? []));
        $preferences[self::PREFERENCE] = array_values(array_unique([...$ids, $businessId]));

        $user->forceFill(['preferences' => $preferences])->save();
    }

    private static function cacheKey(int $businessId): string
    {
        return "dashboard:getting-started:{$businessId}";
    }

    /** @return list<array{key: string, done: bool, count: int|null, of: int|null, url: string}> */
    private static function compute(int $businessId): array
    {
        // ⚠ The opening entries are their own journal type (JournalType), so a
        // ledger that only holds trading is still "not started" here.
        $opening = DB::table('journals')
            ->where('business_id', $businessId)
            ->where('journal_type', JournalType::OpeningBalance->value)
            ->exists();

        // A product's cost lives on its stock batches (opening stock, a goods
        // receipt, found stock), never on the product. Services hold no stock.
        $products = DB::table('products')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('type', '!=', ProductType::Service->value);
        $productCount = (clone $products)->count();
        $costed = (clone $products)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_batches')
                ->whereColumn('stock_batches.product_id', 'products.id')
                ->where('stock_batches.business_id', $businessId)
                ->where('stock_batches.unit_cost', '>', 0))
            ->count();

        $openingStock = DB::table('opening_stocks')->where('business_id', $businessId)->exists();

        // The walk-in counter row is planted by a seeder; it is not a customer anybody added.
        $customers = DB::table('customers')
            ->where('business_id', $businessId)
            // ⚠ A customer may have no code, and NULL != 'x' is not true in SQL.
            ->where(fn ($q) => $q->whereNull('code')->orWhere('code', '!=', Customer::WALK_IN_CODE))
            ->count();

        $suppliers = DB::table('suppliers')->where('business_id', $businessId)->count();

        // Connected AND paired: switched on, and the courier has either paired
        // its webhook with us or already pushed an authentic update.
        $courierLinks = DB::table('courier_account_business')
            ->join('courier_accounts', 'courier_accounts.id', '=', 'courier_account_business.courier_account_id')
            ->where('courier_account_business.business_id', $businessId)
            ->where('courier_accounts.is_active', true);
        $connected = (clone $courierLinks)->distinct()->count('courier_accounts.id');
        $paired = (clone $courierLinks)
            ->where(fn ($q) => $q->where('courier_accounts.webhook_last_handshake_ok', true)->orWhereNotNull('courier_accounts.webhook_last_event_at'))
            ->distinct()
            ->count('courier_accounts.id');

        return [
            ['key' => 'opening_balances', 'done' => $opening, 'count' => null, 'of' => null, 'url' => '/accounting/opening-balances'],
            ['key' => 'product_costs', 'done' => $productCount > 0 && $costed === $productCount, 'count' => $costed, 'of' => $productCount, 'url' => '/inventory/products'],
            ['key' => 'opening_stock', 'done' => $openingStock, 'count' => null, 'of' => null, 'url' => '/inventory/opening-stock'],
            ['key' => 'customers', 'done' => $customers > 0, 'count' => $customers, 'of' => null, 'url' => '/sales/customers'],
            ['key' => 'suppliers', 'done' => $suppliers > 0, 'count' => $suppliers, 'of' => null, 'url' => '/purchasing/suppliers'],
            ['key' => 'courier', 'done' => $paired > 0, 'count' => $paired, 'of' => $connected, 'url' => '/settings/couriers'],
        ];
    }
}
