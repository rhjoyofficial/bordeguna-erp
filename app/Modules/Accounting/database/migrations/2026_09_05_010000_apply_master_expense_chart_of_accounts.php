<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The accountant's Master Expense Chart of Accounts, 5 September 2026.
 *
 * Seventeen groups covering how this business actually spends money: sourcing
 * trips, packaging operations, online and offline marketing, courier, customer
 * service adjustments, events, financial charges, licensing and testing. Until
 * today the chart had twenty-two flat expense accounts under one heading and
 * could not answer "what did Facebook advertising cost in September".
 *
 * ⚠⚠ THE GROUPS ARE NOT A REPORT FEATURE. `StatementGrouping::headerFor` walks
 * up from a posted account to the nearest NON-POSTABLE ancestor and prints that
 * as the section heading, ordering sections by the header's code. So a group is
 * simply a non-postable account. Creating these headers and hanging the
 * postable accounts underneath makes the profit and loss render the document's
 * structure on its own, and NOT ONE REPORT, CONTROLLER OR POSTING RULE CHANGES.
 *
 * ⚠⚠ WHY RENUMBER, AND WHY TODAY. The document's own 01.01 notation cannot be
 * the GL code: this chart is four digits throughout so that lexicographic order
 * IS chart order (Account::scopeInChartOrder). The existing accounts are
 * scattered relative to the new grouping (salaries at 6100, courier at 6235,
 * miscellaneous at 6910), so no assignment of headers leaves them inside their
 * own group's band, and the chart screen would show children detached from
 * their headings. Renumbering is the only clean answer, and TODAY IS THE ONLY
 * CHEAP DAY: the ledger holds fourteen journals and the accountant's opening
 * trial balance has not been entered. The schema was built for this. See the
 * accounts table comment: "accountants renumber a chart and consider it a
 * cosmetic act", which is why every foreign key points at `id`, never `code`.
 *
 * ⚠⚠ THE RENAME RUNS IN TWO PHASES, THROUGH TEMPORARY CODES. `code` is
 * unique, and a single pass would collide the instant a new code lands on one
 * still in use (6230 becomes 6011, but 6130 becomes 6134 while 6130 is also a
 * new header). Vacating every old code first makes the second pass free of
 * order dependence entirely.
 *
 * Rows keep their id, their system_slug and every journal line already posted
 * to them. Only the code string moves.
 */
return new class extends Migration
{
    /**
     * old code => new code. Every one of the twenty-two existing expense
     * accounts appears here exactly once.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
            '6230' => '6011',
            '6232' => '6019',
            '6200' => '6051',
            '6210' => '6052',
            '6220' => '6055',
            '6225' => '6057',
            '6250' => '6058',
            '6100' => '6131',
            '6110' => '6132',
            '6120' => '6133',
            '6130' => '6134',
            '6140' => '6138',
            '6255' => '6209',
            '6260' => '6289',
            '6235' => '6371',
            '6290' => '6471',
            '6270' => '6506',
            '6280' => '6509',
            '6240' => '6556',
            '6910' => '6601',
            '6300' => '6710',
            '6900' => '6810',
    ];

    /**
     * code, name, parent code, postable, system slug.
     *
     * Parents appear before their children: the reparent pass resolves a
     * parent from codes it has already seen.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: ?string}>
     */
    private const BAND = [
            ['6010', 'Travel and Conveyance', '6000', false, null],
            ['6011', 'Conveyance', '6010', true, 'fuel_and_conveyance'],
            ['6012', 'Inter-district Travel', '6010', true, null],
            ['6013', 'Bus, Train, Launch and Air Fare', '6010', true, null],
            ['6014', 'Hotel and Accommodation', '6010', true, null],
            ['6015', 'Business Travel Food', '6010', true, null],
            ['6016', 'Fuel and Vehicle Running', '6010', true, null],
            ['6017', 'Parking and Toll', '6010', true, null],
            ['6018', 'Business Meeting Travel', '6010', true, null],
            ['6019', 'Travel and TA', '6010', true, null],
            ['6050', 'Office and Administration', '6000', false, null],
            ['6051', 'Office Rent', '6050', true, null],
            ['6052', 'Electricity and Gas', '6050', true, null],
            ['6053', 'Water', '6050', true, null],
            ['6054', 'Internet', '6050', true, null],
            ['6055', 'Mobile and Telephone', '6050', true, null],
            ['6056', 'Software Subscription', '6050', true, null],
            ['6057', 'ERP and Hosting', '6050', true, null],
            ['6058', 'Office Stationery', '6050', true, null],
            ['6059', 'Printing and Photocopy', '6050', true, null],
            ['6060', 'Office Cleaning', '6050', true, null],
            ['6061', 'Office Maintenance and Repair', '6050', true, null],
            ['6062', 'Office Consumables', '6050', true, null],
            ['6063', 'Office Small Equipment', '6050', true, null],
            ['6064', 'Business Communication Service', '6050', true, null],
            ['6090', 'Warehouse and Operations', '6000', false, null],
            ['6091', 'Warehouse Rent', '6090', true, null],
            ['6092', 'Warehouse Electricity', '6090', true, null],
            ['6093', 'Warehouse Water', '6090', true, null],
            ['6094', 'Warehouse Maintenance and Repair', '6090', true, null],
            ['6095', 'Warehouse Labour', '6090', true, null],
            ['6096', 'Loading and Unloading Labour', '6090', true, null],
            ['6097', 'Product Handling Labour', '6090', true, null],
            ['6098', 'Cleaning and Sanitation', '6090', true, null],
            ['6099', 'Warehouse Consumables', '6090', true, null],
            ['6130', 'Employee and Staff', '6000', false, null],
            ['6131', 'Salaries and Wages', '6130', true, 'salaries_expense'],
            ['6132', 'Bonus and Festival Allowance', '6130', true, null],
            ['6133', 'Overtime', '6130', true, null],
            ['6134', 'Leave Encashment', '6130', true, 'leave_encashment'],
            ['6135', 'Employee Allowance', '6130', true, null],
            ['6136', 'Mobile Allowance', '6130', true, null],
            ['6137', 'Employee Training', '6130', true, null],
            ['6138', 'Employee Welfare', '6130', true, null],
            ['6139', 'Employee Refreshment', '6130', true, null],
            ['6141', 'Employee Gift', '6130', true, null],
            ['6170', 'Product Sourcing', '6000', false, null],
            ['6171', 'Supplier Visit', '6170', true, null],
            ['6172', 'Factory Visit', '6170', true, null],
            ['6173', 'Market Visit', '6170', true, null],
            ['6174', 'Product Sourcing Visit', '6170', true, null],
            ['6175', 'Product Sample Sourcing', '6170', true, null],
            ['6176', 'Supplier Meeting', '6170', true, null],
            ['6177', 'Product Sourcing Miscellaneous', '6170', true, null],
            ['6200', 'Packaging Operations', '6000', false, null],
            ['6201', 'Packaging Labour', '6200', true, null],
            ['6202', 'Packaging Printing', '6200', true, null],
            ['6203', 'Packaging Design', '6200', true, null],
            ['6204', 'Packaging Development', '6200', true, null],
            ['6205', 'Packaging Testing', '6200', true, null],
            ['6206', 'Packaging Sourcing', '6200', true, null],
            ['6207', 'Packaging Machine Repair and Maintenance', '6200', true, null],
            ['6208', 'Other Packaging Operating', '6200', true, null],
            ['6209', 'Packaging Consumables', '6200', true, null],
            ['6240', 'Marketing - Online', '6000', false, null],
            ['6241', 'Facebook Advertising', '6240', true, null],
            ['6242', 'Facebook Boosting', '6240', true, null],
            ['6243', 'Google Advertising', '6240', true, null],
            ['6244', 'Instagram Advertising', '6240', true, null],
            ['6245', 'TikTok Advertising', '6240', true, null],
            ['6246', 'YouTube Advertising', '6240', true, null],
            ['6247', 'Other Online Advertising', '6240', true, null],
            ['6248', 'Content Creator', '6240', true, null],
            ['6249', 'Food Blogger Promotion', '6240', true, null],
            ['6251', 'Influencer Marketing', '6240', true, null],
            ['6252', 'Online Campaign', '6240', true, null],
            ['6253', 'Website Promotion', '6240', true, null],
            ['6254', 'Product Photography', '6240', true, null],
            ['6256', 'Product Video and Content Production', '6240', true, null],
            ['6257', 'Graphic Design', '6240', true, null],
            ['6258', 'Digital Promotional Material', '6240', true, null],
            ['6289', 'General Marketing and Advertising', '6240', true, null],
            ['6290', 'Marketing - Offline', '6000', false, null],
            ['6291', 'Leaflet Printing', '6290', true, null],
            ['6292', 'Leaflet Distribution', '6290', true, null],
            ['6293', 'Banner and Poster', '6290', true, null],
            ['6294', 'Billboard', '6290', true, null],
            ['6295', 'Miking Campaign', '6290', true, null],
            ['6296', 'Vehicle Branding', '6290', true, null],
            ['6297', 'Roadshow and Campaign', '6290', true, null],
            ['6298', 'Product Sampling', '6290', true, null],
            ['6299', 'Promotional Booth', '6290', true, null],
            ['6301', 'Retail Promotion', '6290', true, null],
            ['6302', 'Dealer and Shop Promotion', '6290', true, null],
            ['6303', 'Offline Campaign', '6290', true, null],
            ['6304', 'Other Offline Marketing', '6290', true, null],
            ['6330', 'Gifts and Promotional', '6000', false, null],
            ['6331', 'Customer Gift', '6330', true, null],
            ['6332', 'Customer Free Product', '6330', true, null],
            ['6333', 'Free Sample', '6330', true, null],
            ['6334', 'Promotional Gift', '6330', true, null],
            ['6335', 'Campaign Gift', '6330', true, null],
            ['6336', 'Promotional Merchandise', '6330', true, null],
            ['6337', 'Other Promotional Items', '6330', true, null],
            ['6370', 'Courier and Delivery', '6000', false, null],
            ['6371', 'Courier Charge', '6370', true, null],
            ['6372', 'Delivery Charge', '6370', true, null],
            ['6373', 'Free Delivery', '6370', true, null],
            ['6374', 'Customer Return Delivery', '6370', true, null],
            ['6375', 'Product Return Transport', '6370', true, null],
            ['6376', 'Special Delivery', '6370', true, null],
            ['6377', 'Courier Adjustment', '6370', true, null],
            ['6400', 'Customer Service and Sales Adjustment', '6000', false, null],
            ['6401', 'Customer Compensation', '6400', true, null],
            ['6402', 'Product Replacement', '6400', true, null],
            ['6403', 'Replacement Delivery', '6400', true, null],
            ['6404', 'Customer Service', '6400', true, null],
            ['6405', 'Customer Complaint Resolution', '6400', true, null],
            ['6430', 'Events and Hospitality', '6000', false, null],
            ['6431', 'Official Event', '6430', true, null],
            ['6432', 'Guest Lunch', '6430', true, null],
            ['6433', 'Guest Refreshment', '6430', true, null],
            ['6434', 'Guest Accommodation', '6430', true, null],
            ['6435', 'Meeting Lunch and Refreshment', '6430', true, null],
            ['6436', 'Staff Event', '6430', true, null],
            ['6437', 'Religious and Official Program', '6430', true, null],
            ['6438', 'Event Decoration', '6430', true, null],
            ['6439', 'Event Venue', '6430', true, null],
            ['6441', 'Event Transport', '6430', true, null],
            ['6442', 'Event Gift', '6430', true, null],
            ['6470', 'Financial and Payment Charges', '6000', false, null],
            ['6471', 'Bank Charge', '6470', true, null],
            ['6472', 'bKash Charge', '6470', true, null],
            ['6473', 'Nagad Charge', '6470', true, null],
            ['6474', 'Rocket Charge', '6470', true, null],
            ['6475', 'Payment Gateway Charge', '6470', true, null],
            ['6476', 'Transaction Charge', '6470', true, null],
            ['6477', 'Other Financial Charges', '6470', true, null],
            ['6500', 'Licence, Testing and Professional', '6000', false, null],
            ['6501', 'Trade Licence', '6500', true, null],
            ['6502', 'BSTI and Certification', '6500', true, null],
            ['6503', 'BSCIC and Registration', '6500', true, null],
            ['6504', 'Sanitary and Premises Licence', '6500', true, null],
            ['6505', 'Lab Testing', '6500', true, null],
            ['6506', 'Professional Consultancy', '6500', true, null],
            ['6507', 'Audit Fee', '6500', true, null],
            ['6508', 'Legal Fee', '6500', true, null],
            ['6509', 'Government Fee', '6500', true, null],
            ['6510', 'Other Regulatory', '6500', true, null],
            ['6550', 'Repair and Maintenance', '6000', false, null],
            ['6551', 'Computer and IT Repair', '6550', true, null],
            ['6552', 'Printer Repair', '6550', true, null],
            ['6553', 'Machinery Repair', '6550', true, null],
            ['6554', 'Electrical Repair', '6550', true, null],
            ['6555', 'Furniture Repair', '6550', true, null],
            ['6556', 'Other Repair and Maintenance', '6550', true, null],
            ['6600', 'Other Approved Business Expense', '6000', false, null],
            ['6601', 'Miscellaneous Approved Expense', '6600', true, null],
            ['6602', 'Other Business Expense', '6600', true, null],
            ['6700', 'Depreciation and Amortisation', '6000', false, null],
            ['6710', 'Depreciation', '6700', true, 'depreciation_expense'],
            ['6800', 'System Accounts', '6000', false, null],
            ['6810', 'Rounding Differences', '6800', true, 'rounding_difference'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // ⚠⚠ THIS RUNS ON A FRESH INSTALLATION TOO, and the first
            // version of it did not. It returned early when 6000 was absent, on
            // the reasoning that the seeder would plant the new shape anyway.
            // It does - but the 4 September migration has ALREADY planted 6232,
            // 6235 and 6255 by then, under codes this change retires, and they
            // survived as parentless orphans that no profit and loss section
            // could ever contain. The structure test caught all three.
            //
            // So the band is ensured here rather than assumed, and the rename
            // below picks those three up exactly as it does in production.
            if (DB::table('accounts')->where('code', '6000')->doesntExist()) {
                DB::table('accounts')->insert([
                    'code' => '6000',
                    'name' => 'Operating Expenses',
                    'type' => 'expense',
                    'is_postable' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Phase 1: vacate every old code.
            foreach (self::RENAMES as $old => $new) {
                DB::table('accounts')->where('code', $old)->update(['code' => 'TMP-'.$old]);
            }

            // Phase 2: every target code is now guaranteed free.
            foreach (self::RENAMES as $old => $new) {
                DB::table('accounts')->where('code', 'TMP-'.$old)->update(['code' => $new]);
            }

            // Plant the headers and everything the document adds. The renamed
            // rows already exist under their new codes, so this touches only
            // what is genuinely new.
            foreach (self::BAND as [$code, $name, $parentCode, $isPostable, $slug]) {
                if (DB::table('accounts')->where('code', $code)->exists()) {
                    continue;
                }

                DB::table('accounts')->insert([
                    'code' => $code,
                    'name' => $name,
                    'type' => 'expense',
                    'is_postable' => $isPostable,
                    'is_active' => true,
                    'system_slug' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Reparenting is a separate pass because a parent must exist before
            // a child can point at it.
            //
            // ⚠⚠ This DOES overwrite parent_id, which the seeder deliberately
            // never does. That is the difference between planting and
            // restructuring: this migration exists precisely to move accounts
            // into their new homes, once, and it is the only thing allowed to.
            $ids = DB::table('accounts')->pluck('id', 'code')->all();

            foreach (self::BAND as [$code, $name, $parentCode, $isPostable, $slug]) {
                if (! isset($ids[$code], $ids[$parentCode])) {
                    continue;
                }

                DB::table('accounts')->where('id', $ids[$code])->update([
                    'parent_id' => $ids[$parentCode],
                    'updated_at' => now(),
                ]);
            }

            // Fills a gap only, so an account somebody deliberately re-slugged
            // is left exactly as they left it.
            foreach (self::BAND as [$code, $name, $parentCode, $isPostable, $slug]) {
                if ($slug === null) {
                    continue;
                }

                DB::table('accounts')
                    ->where('code', $code)
                    ->whereNull('system_slug')
                    ->update(['system_slug' => $slug, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Put the twenty-two back, flat under 6000, and drop what this
            // migration introduced. An account that has been posted to is left
            // alone, which is the correct outcome: a used account is not this
            // migration's to remove.
            $operating = DB::table('accounts')->where('code', '6000')->value('id');

            foreach (self::RENAMES as $old => $new) {
                DB::table('accounts')->where('code', $new)->update(['code' => 'TMP-'.$old]);
            }

            foreach (self::RENAMES as $old => $new) {
                DB::table('accounts')->where('code', 'TMP-'.$old)->update([
                    'code' => $old,
                    'parent_id' => $operating,
                ]);
            }

            $restored = array_values(self::RENAMES);

            foreach (array_column(self::BAND, 0) as $code) {
                if (in_array($code, $restored, true)) {
                    continue;
                }

                $id = DB::table('accounts')->where('code', $code)->value('id');

                if ($id === null || DB::table('journal_lines')->where('account_id', $id)->exists()) {
                    continue;
                }

                DB::table('accounts')->where('id', $id)->delete();
            }
        });
    }
};
