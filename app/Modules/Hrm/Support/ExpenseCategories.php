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
    /**
     * English name => [Bangla name, account code, is_exception, receipt_required]
     *
     * ⚠ Conveyance is the one that asks for no receipt: a rickshaw gives you
     * nothing to photograph, and demanding proof does not produce proof - it
     * pushes the spend off the books, which is worse than an unreceipted line
     * an approver can see and question.
     *
     * ⚠ Software Subscription and ERP and Hosting were added on 10 Sep 2026
     * because the hosting bill had no category and could only be filed as the
     * catch-all, which section 19 of the accountant's document forbids.
     */
    private const DEFAULTS = [
        'Conveyance' => ['যাতায়াতের খরচ', '6011', false, false],
        'Travel and TA' => ['ভ্রমণের খরচ', '6019', false, true],
        'Hotel and Accommodation' => ['হোটেল খরচ', '6014', false, true],
        'Office Expense' => ['অফিসের খরচ', '6062', false, true],
        'Mobile and Telephone' => ['মোবাইল/ফোনের খরচ', '6055', false, true],
        'Internet' => ['ইন্টারনেটের খরচ', '6054', false, true],
        'Product Inward Freight' => ['পণ্য আনার খরচ', '5300', false, true],
        'Product Sourcing' => ['পণ্য সোর্সিং-এর খরচ', '6174', false, true],
        'Packaging' => ['প্যাকেজিং-এর খরচ', '6206', false, true],
        'Marketing' => ['মার্কেটিং-এর খরচ', '6289', false, true],
        'Customer Gift' => ['কাস্টমার গিফট', '6331', false, true],
        'Free Product' => ['ফ্রি পণ্য', '6332', false, true],
        'Courier and Delivery' => ['কুরিয়ার/ডেলিভারি', '6371', false, true],
        'Event and Guest Hospitality' => ['ইভেন্ট/অতিথি আপ্যায়ন', '6431', false, true],
        'Employee Welfare' => ['কর্মচারী সংক্রান্ত খরচ', '6138', false, true],
        'Bank and MFS Charge' => ['ব্যাংক/এমএফএস চার্জ', '6471', false, true],
        'Licence and Testing' => ['লাইসেন্স/টেস্টিং', '6505', false, true],
        'Repair and Maintenance' => ['মেরামত/রক্ষণাবেক্ষণ', '6556', false, true],
        'Software Subscription' => ['সফটওয়্যার সাবস্ক্রিপশন', '6056', false, true],
        'ERP and Hosting' => ['ইআরপি/হোস্টিং', '6057', false, true],
        'Other Approved Business Expense' => ['অন্যান্য অনুমোদিত খরচ', '6601', true, true],
    ];

    public static function ensureDefaults(int $businessId): void
    {
        if (DB::table('expense_categories')->where('business_id', $businessId)->exists()) {
            return;
        }

        $sort = 0;

        foreach (self::DEFAULTS as $name => [$nameBn, $code, $isException, $receiptRequired]) {
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
                    'receipt_required' => $receiptRequired,
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
            // ⚠ Defaulted TRUE when the column is missing rather than false: a
            // category nobody has decided about must keep refusing a line with
            // no proof, which is what this module has always done.
            'receipt_required' => (bool) ($category->receipt_required ?? true),
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
                // Rickshaw fare and tea come with no piece of paper. The
                // accountant decides which categories those are; every other
                // one still refuses a line without a file.
                'receipt_required' => (bool) ($category->receipt_required ?? true),
            ]);
    }
}
