<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employee-facing categories from section 19 of the Master Expense Chart
 * of Accounts, 5 September 2026.
 *
 * The document's argument, and it is a good one: an employee should not be
 * shown a hundred and forty-five accounting names. They should be shown the
 * handful of things they actually spend money on, in their own language, and
 * the ERP should do the classifying. So nineteen simple categories replace the
 * twelve that were here, each pointing at exactly one account.
 *
 * ⚠⚠ THE BANGLA NAME IS A COLUMN, NOT A DICTIONARY ENTRY. `lib/i18n.ts` is a
 * compile-time object keyed by a literal string, which is right for interface
 * text and useless for rows somebody adds later on the categories screen. A
 * category invented next month needs a Bangla label too, and only a column can
 * carry one. English remains the fallback when it is blank.
 *
 * ⚠⚠ NOT EVERY CATEGORY POINTS AT AN EXPENSE. "Product Inward Freight" maps
 * to 5300 Freight and Carriage Inward, which is Cost of Sales, because freight
 * to bring goods in belongs in the cost of those goods and not in overhead.
 * The mapping is per category and always has been; nothing assumes 6xxx.
 *
 * ⚠⚠ `Purchase of Materials` IS RETIRED, AND THE DOCUMENT IS WHY. Its section
 * 18 is explicit that raw material purchase is inventory and must never be an
 * expense, and that category posted to 6910 Miscellaneous. This settles the
 * question put to the owner on 3 September. Staff-bought raw material should
 * arrive as stock through a purchase, not as a Miscellaneous expense claim.
 *
 * The twelve are deactivated rather than deleted. A category a claim once
 * pointed at is part of that claim's history, and the accountant can switch any
 * of them back on from the categories screen if the office disagrees.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: bool}> */
    private const CATEGORIES = [
        ['Conveyance', 'যাতায়াতের খরচ', '6011', false],
        ['Travel and TA', 'ভ্রমণের খরচ', '6019', false],
        ['Hotel and Accommodation', 'হোটেল খরচ', '6014', false],
        ['Office Expense', 'অফিসের খরচ', '6062', false],
        ['Mobile and Telephone', 'মোবাইল/ফোনের খরচ', '6055', false],
        ['Internet', 'ইন্টারনেটের খরচ', '6054', false],
        ['Product Inward Freight', 'পণ্য আনার খরচ', '5300', false],
        ['Product Sourcing', 'পণ্য সোর্সিং-এর খরচ', '6174', false],
        ['Packaging', 'প্যাকেজিং-এর খরচ', '6206', false],
        ['Marketing', 'মার্কেটিং-এর খরচ', '6289', false],
        ['Customer Gift', 'কাস্টমার গিফট', '6331', false],
        ['Free Product', 'ফ্রি পণ্য', '6332', false],
        ['Courier and Delivery', 'কুরিয়ার/ডেলিভারি', '6371', false],
        ['Event and Guest Hospitality', 'ইভেন্ট/অতিথি আপ্যায়ন', '6431', false],
        ['Employee Welfare', 'কর্মচারী সংক্রান্ত খরচ', '6138', false],
        ['Bank and MFS Charge', 'ব্যাংক/এমএফএস চার্জ', '6471', false],
        ['Licence and Testing', 'লাইসেন্স/টেস্টিং', '6505', false],
        ['Repair and Maintenance', 'মেরামত/রক্ষণাবেক্ষণ', '6556', false],
        ['Other Approved Business Expense', 'অন্যান্য অনুমোদিত খরচ', '6601', true],
    ];

    /** @var list<string> */
    private const RETIRED = [
        'Mobile / Telecom Expense',
        'Transport Expense',
        'Courier Expense',
        'Office Stationery',
        'Office Refreshment',
        'Printing & Photocopy',
        'Travel / TA',
        'Training Expense',
        'Packaging Expense',
        'Purchase of Materials',
        'Software / Subscription',
        'Other Approved Expense',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('expense_categories', 'name_bn')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->string('name_bn')->nullable()->after('name');
            });
        }

        $businesses = DB::table('expense_categories')->distinct()->pluck('business_id');

        foreach ($businesses as $businessId) {
            DB::table('expense_categories')
                ->where('business_id', $businessId)
                ->whereIn('name', self::RETIRED)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $sort = 0;

            foreach (self::CATEGORIES as [$name, $nameBn, $code, $isException]) {
                $accountId = DB::table('accounts')->where('code', $code)->value('id');

                // A chart that does not carry the account yet is not a reason
                // to plant a category nobody can claim under: the form requires
                // an account, and a null one would lock every employee out.
                if ($accountId === null) {
                    continue;
                }

                $existing = DB::table('expense_categories')
                    ->where('business_id', $businessId)
                    ->where('name', $name)
                    ->first();

                if ($existing === null) {
                    DB::table('expense_categories')->insert([
                        'business_id' => $businessId,
                        'name' => $name,
                        'name_bn' => $nameBn,
                        'account_id' => $accountId,
                        'is_exception' => $isException,
                        'is_active' => true,
                        'sort' => $sort,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    // ⚠⚠ The account mapping is NOT overwritten. The
                    // accountant owns it from the categories screen, and this
                    // migration must never undo a remap he made on purpose.
                    DB::table('expense_categories')
                        ->where('id', $existing->id)
                        ->update([
                            'name_bn' => $nameBn,
                            'is_active' => true,
                            'sort' => $sort,
                            'updated_at' => now(),
                        ]);
                }

                $sort++;
            }
        }
    }

    public function down(): void
    {
        $names = array_column(self::CATEGORIES, 0);

        DB::table('expense_categories')
            ->whereIn('name', $names)
            ->whereNotIn('name', self::RETIRED)
            ->delete();

        DB::table('expense_categories')
            ->whereIn('name', self::RETIRED)
            ->update(['is_active' => true, 'updated_at' => now()]);

        if (Schema::hasColumn('expense_categories', 'name_bn')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropColumn('name_bn');
            });
        }
    }
};
