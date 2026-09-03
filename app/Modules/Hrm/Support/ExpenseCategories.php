<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * The employee-facing expense categories and the COA mapping behind them
 * (the accountant's structure, 3 Sep 2026). The employee picks a name;
 * the accountant owns which account it lands in; nobody teaches staff
 * account codes.
 *
 * Defaults are planted idempotently, each resolving its account BY CODE at
 * plant time - environments differ, and a category that cannot find its
 * account falls back to Miscellaneous rather than failing. The accountant
 * remaps on the categories screen.
 */
class ExpenseCategories
{
    /** name => [account code, is_exception] */
    private const DEFAULTS = [
        'Mobile / Telecom Expense' => ['6220', false],
        'Transport Expense' => ['6230', false],
        'Courier Expense' => ['6235', false],
        'Office Stationery' => ['6250', false],
        'Office Refreshment' => ['6140', false],
        'Printing & Photocopy' => ['6250', false],
        'Travel / TA' => ['6232', false],
        'Training Expense' => ['6140', false],
        'Packaging Expense' => ['6255', false],
        // §6: a catch-all an employee could pour anything into. Allowed, but
        // flagged, so the approver is told to look twice before saying yes.
        'Purchase of Materials' => ['6910', true],
        'Software / Subscription' => ['6225', false],
        'Other Approved Expense' => ['6910', true],
    ];

    public static function ensureDefaults(int $businessId): void
    {
        if (DB::table('expense_categories')->where('business_id', $businessId)->exists()) {
            return;
        }

        // ⚠ A category with no account is a category nobody can claim under,
        // and the form REQUIRES one - so a chart missing 6910 would lock every
        // employee out of claiming. Any postable expense account beats that.
        $fallback = Account::query()->where('code', '6910')->first()
            ?? Account::query()->where('type', 'expense')->where('is_postable', true)->orderBy('code')->first();

        $sort = 0;
        foreach (self::DEFAULTS as $name => [$code, $isException]) {
            $account = Account::query()->where('code', $code)->first() ?? $fallback;
            if ($account === null) {
                continue;
            }

            DB::table('expense_categories')->updateOrInsert(
                ['business_id' => $businessId, 'name' => $name],
                [
                    'account_id' => $account->id,
                    'is_exception' => $isException,
                    'is_active' => true,
                    'sort' => $sort++,
                    'updated_at' => now(), 'created_at' => now(),
                ],
            );
        }
    }

    /**
     * One category as the humans need to read it: the name staff picked, the
     * account it will post to, and whether it is a catch-all that deserves a
     * second look. Returns null for a legacy claim that carries no category.
     */
    public static function describe(?int $categoryId): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        $category = DB::table('expense_categories')->where('id', $categoryId)->first();

        if ($category === null) {
            return null;
        }

        return [
            'name' => $category->name,
            'is_exception' => (bool) $category->is_exception,
            'account_id' => (int) $category->account_id,
            'account' => Account::find($category->account_id)?->label(),
        ];
    }

    public static function active(int $businessId)
    {
        return DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_exception' => (bool) $category->is_exception,
            ]);
    }
}
