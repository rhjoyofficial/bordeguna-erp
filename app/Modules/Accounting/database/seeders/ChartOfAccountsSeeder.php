<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Seeder;

/**
 * A standard Bangladeshi SME Chart of Accounts, as the system default.
 *
 * C-LGR-1: Joy's instruction was to draft a clean standard chart rather than
 * wait for an accountant, treat it as the default, and keep it easy to extend or
 * change. It will be reviewed with the accountant before Accounting goes live.
 *
 * ⚠⚠ THE SEEDER PLANTS, IT DOES NOT ENFORCE. Every row is firstOrCreate on the
 * code, so a re-run ADDS anything new and never overwrites a name, a parent or a
 * deactivation somebody changed on purpose. Once this has run, the chart belongs
 * to the business, not to this file. The cost of that choice, stated plainly: a
 * typo corrected here does not propagate to an installation that already ran it.
 * That is the right way round - the alternative silently reverts deliberate
 * edits, and a chart of accounts is exactly the kind of thing a real accountant
 * edits.
 *
 * ⚠ Idempotent per ROW, not all-or-nothing. This project has twice shipped a
 * seeder that skipped its whole body when it found its first record already
 * present, so a later addition was never planted on an installation that had run
 * the earlier version.
 *
 * ⚠ Codes are all four digits so that lexicographic ordering IS chart ordering
 * (see Account::scopeInChartOrder). A three-digit code sorts before every
 * four-digit one and quietly moves an account to the top of the report.
 *
 * ℹ NO VAT ACCOUNTS, on purpose. C-ACC-1 settled that Bor De Guna is not doing
 * VAT accounting yet and that unit costs are recorded gross of any tax charged.
 * Input and output VAT accounts arrive with that decision; seeding them now
 * would suggest a treatment nobody has chosen.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code, name, type, parent code, postable, system slug.
     *
     * ⚠ Parents appear before their children. The loop resolves a parent from
     * the codes it has already planted, so order here is load-bearing.
     *
     * @var list<array{0: string, 1: string, 2: AccountType, 3: ?string, 4: bool, 5: ?string}>
     */
    private const ACCOUNTS = [
        // ─── 1000 Assets ────────────────────────────────────────────────────
        ['1000', 'Assets', AccountType::Asset, null, false, null],
        ['1100', 'Current Assets', AccountType::Asset, '1000', false, null],
        ['1110', 'Cash in Hand', AccountType::Asset, '1100', true, 'cash_in_hand'],
        ['1120', 'Cash at Bank', AccountType::Asset, '1100', true, 'cash_at_bank'],
        ['1130', 'Mobile Financial Services', AccountType::Asset, '1100', true, 'mobile_financial_services'],
        ['1140', 'Accounts Receivable', AccountType::Asset, '1100', true, 'accounts_receivable'],
        ['1150', 'Inventory - Stock on Hand', AccountType::Asset, '1100', true, 'stock_on_hand'],
        // ⚠⚠ The mirror of Goods Received Not Invoiced (2120), on the selling
        // side. Stock the business no longer holds but has not yet earned
        // revenue against - goods out on a delivery challan, not yet invoiced.
        //
        // Without it, cost lands in the month the lorry left and revenue lands
        // in the month the invoice was raised, so every gross margin is drawn
        // from two different periods. Joy's distributor flow is "challan today,
        // invoice at month end", which makes that split the normal case rather
        // than the exception.
        //
        // ⚠ Not a suspense account. Its balance is a report somebody asks for:
        // what is out and not yet billed. See SALES-PHASE1-DESIGN.md §2.
        ['1155', 'Goods Delivered Not Invoiced', AccountType::Asset, '1100', true, 'goods_delivered_not_invoiced'],
        ['1160', 'Advances to Suppliers', AccountType::Asset, '1100', true, null],
        ['1170', 'Employee Advances and Loans', AccountType::Asset, '1100', true, 'employee_advances'],
        ['1180', 'Prepaid Expenses', AccountType::Asset, '1100', true, null],
        // ⚠⚠ C-VAT-6. VAT paid on purchases, reclaimable from the government -
        // money owed BACK to the business, so an asset. Kept strictly separate
        // from output VAT below and NEVER netted in the ledger; the net figure
        // is a report, the same rule GRNI follows.
        // ⚠ Planted now and posted to by nothing. The engine is dormant.
        ['1185', 'Input VAT Receivable', AccountType::Asset, '1100', true, 'input_vat_receivable'],
        // Deducted at source by customers and set against the year's tax bill.
        // It is money owed back to the business, so it is an asset, not a cost.
        ['1190', 'Advance Income Tax', AccountType::Asset, '1100', true, null],

        ['1200', 'Non-Current Assets', AccountType::Asset, '1000', false, null],
        ['1210', 'Furniture and Fixtures', AccountType::Asset, '1200', true, null],
        ['1220', 'Office Equipment', AccountType::Asset, '1200', true, null],
        ['1230', 'Vehicles', AccountType::Asset, '1200', true, null],
        // ⚠ A contra-asset: it lives under Assets and normally carries a credit
        // balance. That needs no special account type because the ledger stores
        // signed amounts - the sign does the work a "contra" flag would.
        ['1290', 'Accumulated Depreciation', AccountType::Asset, '1200', true, 'accumulated_depreciation'],

        // ─── 2000 Liabilities ───────────────────────────────────────────────
        ['2000', 'Liabilities', AccountType::Liability, null, false, null],
        ['2100', 'Current Liabilities', AccountType::Liability, '2000', false, null],
        ['2110', 'Accounts Payable', AccountType::Liability, '2100', true, 'accounts_payable'],
        // The gap between what has been received and what has been invoiced.
        // Without it, stock exists on the balance sheet with no liability behind
        // it and the month does not close.
        ['2120', 'Goods Received Not Invoiced', AccountType::Liability, '2100', true, 'goods_received_not_invoiced'],
        ['2130', 'Salaries and Wages Payable', AccountType::Liability, '2100', true, 'salaries_payable'],
        // Tax and provident fund withheld from pay and owed onward. Slugged
        // because payroll posting has to find it (Phase 3).
        ['2140', 'Employee Deductions Payable', AccountType::Liability, '2100', true, 'employee_deductions_payable'],
        ['2150', 'Advances from Customers', AccountType::Liability, '2100', true, null],
        ['2160', 'Accrued Expenses', AccountType::Liability, '2100', true, null],
        // ⚠⚠ C-VAT-6. VAT charged to customers and owed onward to the
        // government. It is never the business's money, which is exactly why it
        // is a liability and not revenue - and why a VAT-inclusive price
        // (C-VAT-1) overstates revenue until the engine extracts this out of it.
        // ⚠ Planted now and posted to by nothing. The engine is dormant.
        ['2170', 'Output VAT Payable', AccountType::Liability, '2100', true, 'output_vat_payable'],

        ['2200', 'Non-Current Liabilities', AccountType::Liability, '2000', false, null],
        ['2210', 'Bank Loans', AccountType::Liability, '2200', true, null],
        ['2220', 'Loans from Owners', AccountType::Liability, '2200', true, null],

        // ─── 3000 Equity ────────────────────────────────────────────────────
        ['3000', 'Equity', AccountType::Equity, null, false, null],
        ['3100', "Owner's Capital", AccountType::Equity, '3000', true, 'owners_capital'],
        ['3200', "Owner's Drawings", AccountType::Equity, '3000', true, null],
        ['3300', 'Retained Earnings', AccountType::Equity, '3000', true, 'retained_earnings'],
        // ⚠⚠ The account that PROVES the opening balances. Every opening figure
        // is entered against it, and when the last one is in it must read zero.
        // A non-zero balance here is the arithmetic saying an opening figure is
        // missing or wrong, before it has polluted a year of trading. It stays
        // at zero forever afterwards, and the integrity command will say so.
        ['3900', 'Opening Balance Equity', AccountType::Equity, '3000', true, 'opening_balance_equity'],

        // ─── 4000 Income ────────────────────────────────────────────────────
        ['4000', 'Income', AccountType::Income, null, false, null],
        ['4100', 'Sales Revenue', AccountType::Income, '4000', true, 'sales_revenue'],
        // ⚠ Slug added for Phase 4 (sales returns, 2026-08-12). The
        // set-when-absent branch below carries it onto installations seeded
        // before it existed, so re-running this seeder is the whole upgrade.
        ['4200', 'Sales Returns and Discounts', AccountType::Income, '4000', true, 'sales_returns'],
        // ⚠ A contra-revenue account: it lives under Income and normally carries
        // a DEBIT balance, which needs no special type because the ledger stores
        // signed amounts.
        //
        // ⚠⚠ Why a new code rather than a slug on 4200: this seeder is
        // firstOrCreate on the code, so a slug added to a row that already
        // exists would never reach an installation that has already run - and
        // production has. A new code plants cleanly. It also separates a
        // discount from a return, which are different events that happened to
        // share a line in the default chart, and leaves 4200 to Phase 4.
        ['4210', 'Discounts Allowed', AccountType::Income, '4000', true, 'discounts_allowed'],
        ['4900', 'Other Income', AccountType::Income, '4000', true, null],

        // ─── 5000 Cost of Sales ─────────────────────────────────────────────
        ['5000', 'Cost of Sales', AccountType::Expense, null, false, null],
        ['5100', 'Cost of Goods Sold', AccountType::Expense, '5000', true, 'cost_of_goods_sold'],
        // Where the six inventory adjustment types land: damaged, expired, lost,
        // count variance. They were built as the discriminator for exactly this.
        ['5200', 'Inventory Write-offs and Shrinkage', AccountType::Expense, '5000', true, 'inventory_write_off'],
        ['5300', 'Freight and Carriage Inward', AccountType::Expense, '5000', true, null],

        // ─── 6000 Operating Expenses ────────────────────────────────────────
        ['6000', 'Operating Expenses', AccountType::Expense, null, false, null],
        ['6100', 'Salaries and Wages', AccountType::Expense, '6000', true, 'salaries_expense'],
        ['6110', 'Bonus and Festival Allowance', AccountType::Expense, '6000', true, null],
        ['6120', 'Overtime', AccountType::Expense, '6000', true, null],
        ['6130', 'Leave Encashment', AccountType::Expense, '6000', true, null],
        ['6140', 'Staff Welfare', AccountType::Expense, '6000', true, null],
        ['6200', 'Rent', AccountType::Expense, '6000', true, null],
        ['6210', 'Electricity, Gas and Water', AccountType::Expense, '6000', true, null],
        ['6220', 'Internet and Telephone', AccountType::Expense, '6000', true, null],
        ['6230', 'Fuel and Conveyance', AccountType::Expense, '6000', true, 'fuel_and_conveyance'],
        ['6240', 'Repairs and Maintenance', AccountType::Expense, '6000', true, null],
        ['6250', 'Office Supplies and Stationery', AccountType::Expense, '6000', true, null],
        ['6260', 'Marketing and Advertising', AccountType::Expense, '6000', true, null],
        ['6270', 'Professional and Legal Fees', AccountType::Expense, '6000', true, null],
        // Trade licence renewal, municipal fees, stamps.
        ['6280', 'Government Fees, Licences and Taxes', AccountType::Expense, '6000', true, null],
        ['6290', 'Bank Charges', AccountType::Expense, '6000', true, null],
        ['6300', 'Depreciation', AccountType::Expense, '6000', true, 'depreciation_expense'],
        // ⚠⚠ C-LGR-4. Inventory carries costs to four decimal places and the
        // ledger stores whole paisa, so a posting can leave a fraction of a
        // paisa that has to land somewhere VISIBLE rather than being absorbed
        // into whichever line was computed last. This account should hold
        // single-digit taka forever; a growing balance means something upstream
        // is wrong, and that signal is the entire point of not hiding it.
        ['6900', 'Rounding Differences', AccountType::Expense, '6000', true, 'rounding_difference'],
        ['6910', 'Miscellaneous Expenses', AccountType::Expense, '6000', true, null],
    ];

    public function run(): void
    {
        /** @var array<string, int> $idsByCode */
        $idsByCode = Account::query()->pluck('id', 'code')->all();

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode, $isPostable, $slug]) {
            $account = Account::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parentCode === null ? null : ($idsByCode[$parentCode] ?? null),
                    'is_postable' => $isPostable,
                ],
            );

            // ⚠ Set OUTSIDE the mass assignment, because system_slug is not
            // fillable: no request path may ever point `stock_on_hand` at a
            // different account. Same reasoning as businesses.is_default in W2.
            // ⚠ Only when absent, so an installation seeded before a slug
            // existed picks it up, and one where somebody moved it deliberately
            // is left alone.
            if ($slug !== null && $account->system_slug === null) {
                $account->forceFill(['system_slug' => $slug])->save();
            }

            $idsByCode[$code] = $account->id;
        }
    }
}
