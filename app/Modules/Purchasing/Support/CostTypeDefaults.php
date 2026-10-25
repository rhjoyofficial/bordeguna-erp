<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\PurchaseCostTypeDefault;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's answer 3 (25 Oct 2026): what a purchase cost type does by default
 * in the CURRENT business, as the Owner set it on Finance policy > Purchase
 * cost types: into the stock cost (every type, until he edits a row) or an
 * expense under his chosen head.
 *
 * Every purchase screen reads it through LandedCostType::defaultTreatment()
 * and ::defaultExpenseAccountId(), so the full purchase, Buy and receive, the
 * Landed costs popup and the later-charge popup all follow one setting. The
 * per-line switch (with its reason) is untouched.
 *
 * ⚠ Cached per business on the container for the request (a test's fresh
 * application starts empty); a saved or deleted row forgets it. Outside a
 * business (a console command with no context, the table not yet migrated)
 * the answer is today's default: stock cost.
 */
final class CostTypeDefaults
{
    private const CACHE = 'purchasing.cost_type_defaults';

    public static function treatmentOf(LandedCostType $type, ?int $businessId = null): LandedCostTreatment
    {
        return self::rowFor($type, $businessId)['treatment'] ?? LandedCostTreatment::Capitalize;
    }

    /** The head an expensed line of this type posts to when the line names none; null means "as mapped, or named on the line". */
    public static function expenseAccountIdOf(LandedCostType $type, ?int $businessId = null): ?int
    {
        return self::rowFor($type, $businessId)['expense_account_id'] ?? null;
    }

    /**
     * Every type with the default that applies now, for the settings screen.
     *
     * @return array<string, array{treatment: LandedCostTreatment, expense_account_id: ?int}>
     */
    public static function all(?int $businessId = null): array
    {
        $businessId ??= app(BusinessContext::class)->id();
        $rows = [];

        foreach (LandedCostType::cases() as $type) {
            $rows[$type->value] = self::rowFor($type, $businessId) ?? ['treatment' => LandedCostTreatment::Capitalize, 'expense_account_id' => null];
        }

        return $rows;
    }

    public static function forget(): void
    {
        app()->instance(self::CACHE, []);
    }

    /** @return array{treatment: LandedCostTreatment, expense_account_id: ?int}|null */
    private static function rowFor(LandedCostType $type, ?int $businessId): ?array
    {
        $businessId ??= app(BusinessContext::class)->id();

        if ($businessId === null) {
            return null;
        }

        /** @var array<int, array<string, array{treatment: LandedCostTreatment, expense_account_id: ?int}>> $cache */
        $cache = app()->bound(self::CACHE) ? app(self::CACHE) : [];

        if (! array_key_exists($businessId, $cache)) {
            $cache[$businessId] = self::load($businessId);
            app()->instance(self::CACHE, $cache);
        }

        return $cache[$businessId][$type->value] ?? null;
    }

    /** @return array<string, array{treatment: LandedCostTreatment, expense_account_id: ?int}> */
    private static function load(int $businessId): array
    {
        if (! Schema::hasTable('purchase_cost_type_defaults')) {
            return [];
        }

        $rows = [];

        foreach (PurchaseCostTypeDefault::withoutGlobalScopes()->where('business_id', $businessId)->get() as $row) {
            if (! $row->cost_type instanceof LandedCostType || ! $row->treatment instanceof LandedCostTreatment) {
                continue;
            }

            $rows[$row->cost_type->value] = [
                'treatment' => $row->treatment,
                // An expense head only matters when the default IS expense; a
                // head left on a stock-cost row is ignored, never posted.
                'expense_account_id' => $row->treatment === LandedCostTreatment::Expense && $row->expense_account_id !== null ? (int) $row->expense_account_id : null,
            ];
        }

        return $rows;
    }
}
