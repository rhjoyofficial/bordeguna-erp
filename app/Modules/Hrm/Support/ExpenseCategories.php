<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * The employee-facing expense categories and the COA mapping behind them:
 * section 19 of the accountant's Master Expense Chart of Accounts, 5 Sep 2026.
 *
 * The document's argument, and it is a good one. An employee should never be
 * shown a hundred and forty-five accounting names. They should see the handful
 * of things they actually spend money on, in their own language, and the ERP
 * does the classifying behind them. The employee says what the money was for;
 * the ERP says which account that is; the accountant says whether the proof and
 * the approval are in order.
 *
 * ⚠⚠ "Miscellaneous must NOT be the default category" is the document's own
 * sentence. Exactly one category here is a catch-all, and it carries
 * `is_exception` so the approver is told to look twice before saying yes.
 *
 * ⚠⚠ NOT EVERY CATEGORY POINTS AT AN EXPENSE ACCOUNT. Product Inward Freight
 * maps to 5300 Freight and Carriage Inward, which is Cost of Sales: freight to
 * bring goods in belongs in the cost of those goods, not in overhead. The
 * mapping is per category and nothing here assumes a 6xxx code.
 *
 * ⚠⚠ RAW MATERIAL IS NOT ON THIS LIST, ON PURPOSE. Section 18 of the document
 * forbids product purchase, raw material, packaging material, fixed assets and
 * advances from ever being an expense. The old `Purchase of Materials` category
 * broke that rule and was retired on 5 Sep; staff-bought raw material should
 * arrive as stock through a purchase, not as a claim.
 *
 * Defaults are planted idempotently, each resolving its account BY CODE at
 * plant time, because environments differ. A category that cannot find its
 * account is skipped rather than planted pointing at nothing: the claim form
 * requires an account, and a null one would lock every employee out of
 * claiming.
 */
class ExpenseCategories
{
    /** English name => [Bangla name, account code, is_exception] */
    private const DEFAULTS = [
        'Conveyance' => ['যাতায়াতের খরচ', '6011', false],
        'Travel and TA' => ['ভ্রমণের খরচ', '6019', false],
        'Hotel and Accommodation' => ['হোটেল খরচ', '6014', false],
        'Office Expense' => ['অফিসের খরচ', '6062', false],
        'Mobile and Telephone' => ['মোবাইল/ফোনের খরচ', '6055', false],
        'Internet' => ['ইন্টারনেটের খরচ', '6054', false],
        'Product Inward Freight' => ['পণ্য আনার খরচ', '5300', false],
        'Product Sourcing' => ['পণ্য সোর্সিং-এর খরচ', '6174', false],
        'Packaging' => ['প্যাকেজিং-এর খরচ', '6206', false],
        'Marketing' => ['মার্কেটিং-এর খরচ', '6289', false],
        'Customer Gift' => ['কাস্টমার গিফট', '6331', false],
        'Free Product' => ['ফ্রি পণ্য', '6332', false],
        'Courier and Delivery' => ['কুরিয়ার/ডেলিভারি', '6371', false],
        'Event and Guest Hospitality' => ['ইভেন্ট/অতিথি আপ্যায়ন', '6431', false],
        'Employee Welfare' => ['কর্মচারী সংক্রান্ত খরচ', '6138', false],
        'Bank and MFS Charge' => ['ব্যাংক/এমএফএস চার্জ', '6471', false],
        'Licence and Testing' => ['লাইসেন্স/টেস্টিং', '6505', false],
        'Repair and Maintenance' => ['মেরামত/রক্ষণাবেক্ষণ', '6556', false],
        'Other Approved Business Expense' => ['অন্যান্য অনুমোদিত খরচ', '6601', true],
    ];

    public static function ensureDefaults(int $businessId): void
    {
        if (DB::table('expense_categories')->where('business_id', $businessId)->exists()) {
            return;
        }

        $sort = 0;

        foreach (self::DEFAULTS as $name => [$nameBn, $code, $isException]) {
            $account = Account::query()->where('code', $code)->first();

            if ($account === null) {
                continue;
            }

            DB::table('expense_categories')->updateOrInsert(
                ['business_id' => $businessId, 'name' => $name],
                [
                    'name_bn' => $nameBn,
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
            'name_bn' => $category->name_bn ?? null,
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
                // ⚠⚠ Null, never the English name, so the screen decides the
                // fallback. Substituting here would make a missing Bangla label
                // indistinguishable from a deliberate English one.
                'name_bn' => $category->name_bn ?? null,
                'is_exception' => (bool) $category->is_exception,
            ]);
    }
}
