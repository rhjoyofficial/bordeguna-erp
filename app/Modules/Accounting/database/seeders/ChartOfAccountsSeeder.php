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
 * ⚠ Codes are four digits so that lexicographic ordering IS chart ordering
 * (see Account::scopeInChartOrder). A three-digit code sorts before every
 * four-digit one and quietly moves an account to the top of the report. The one
 * exception is the head of a cash box, bank account or wallet: its group's code
 * without the trailing zero, plus two digits (11201 under 1120), which sorts
 * straight after the group and before the next four-digit code.
 *
 * ℹ NO VAT ACCOUNTS, on purpose. C-ACC-1 settled that Bor de Guna is not doing
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
        // ⚠⚠ Cash, bank and mobile wallets are GROUPS with one head per money
        // account under each (21 Sep 2026, Support\MoneyLedgers). The heads are
        // created by the system when a Bank & cash account is saved; the three
        // planted here are placeholders the first account of each kind takes
        // over, and they carry the slugs the chart has always had.
        ['1110', 'Cash in Hand', AccountType::Asset, '1100', false, 'cash_accounts'],
        ['11101', 'Main Cash', AccountType::Asset, '1110', true, 'cash_in_hand'],
        ['1120', 'Bank Accounts', AccountType::Asset, '1100', false, 'bank_accounts'],
        ['11201', 'Main Bank Account', AccountType::Asset, '1120', true, 'cash_at_bank'],
        ['1130', 'Mobile Financial Services', AccountType::Asset, '1100', false, 'mfs_accounts'],
        ['11301', 'Main Mobile Wallet', AccountType::Asset, '1130', true, 'mobile_financial_services'],
        ['1140', 'Accounts Receivable', AccountType::Asset, '1100', true, 'accounts_receivable'],
        // Cash on delivery a courier has collected and not yet paid out (13 Sep
        // 2026). A receivable, not cash: the courier owes it, net of its fees,
        // on its own payout terms.
        ['1145', 'Receivable from Couriers', AccountType::Asset, '1100', true, 'courier_receivable'],
        // Money an online payment gateway has taken from customers and not yet
        // settled to the bank (14 Sep 2026). Owed by the gateway, net of its fee.
        ['1146', 'Receivable from Payment Gateways', AccountType::Asset, '1100', true, 'gateway_receivable'],
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
        ['1160', 'Advances to Suppliers', AccountType::Asset, '1100', true, 'supplier_advances'],
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
        ['2145', 'Employee Reimbursements Payable', AccountType::Liability, '2100', true, 'employee_reimbursements_payable'],
        ['2150', 'Advances from Customers', AccountType::Liability, '2100', true, 'customer_advances'],
        ['2160', 'Accrued Expenses', AccountType::Liability, '2100', true, null],
        // ⚠⚠ C-VAT-6. VAT charged to customers and owed onward to the
        // government. It is never the business's money, which is exactly why it
        // is a liability and not revenue - and why a VAT-inclusive price
        // (C-VAT-1) overstates revenue until the engine extracts this out of it.
        // ⚠ Planted now and posted to by nothing. The engine is dormant.
        ['2170', 'Output VAT Payable', AccountType::Liability, '2100', true, 'output_vat_payable'],
        // The VAT return's own account (D9). Filing a return moves the period's
        // output and input VAT here; what is left is what is owed to NBR, paid by
        // an ordinary payment voucher. A credit balance owed TO us sits as a debit.
        ['2175', 'VAT Settlement', AccountType::Liability, '2100', true, 'vat_settlement'],

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
        // ⭐ Joy, 13 September: what a customer pays for DELIVERY is income of its
        // own, shown beside sales revenue rather than inside it. What the courier
        // charges the shop stays an expense (6371), so the profit and loss shows
        // both halves of delivery instead of a net figure nobody can explain.
        ['4300', 'Delivery Charges Income', AccountType::Income, '4000', true, 'delivery_income'],
        // ⚠⚠ A HEADING, NOT A POSTABLE ACCOUNT, since the income voucher
        // landed (6 Sep 2026). It was postable and unreachable for a month:
        // nothing in the application could write to it, so scrap, interest and
        // refunds had to be hand-written journals. Now it groups the heads
        // below, and the profit and loss prints "Other Income" as its own
        // section for the same reason the expense groups do.
        ['4900', 'Other Income', AccountType::Income, '4000', false, null],
        ['4910', 'Scrap and Waste Sales', AccountType::Income, '4900', true, null],
        ['4920', 'Interest Income', AccountType::Income, '4900', true, null],
        ['4930', 'Rent Received', AccountType::Income, '4900', true, null],
        ['4940', 'Supplier Rebates and Refunds', AccountType::Income, '4900', true, null],
        ['4990', 'Miscellaneous Income', AccountType::Income, '4900', true, null],

        // ─── 5000 Cost of Sales ─────────────────────────────────────────────
        ['5000', 'Cost of Sales', AccountType::Expense, null, false, null],
        ['5100', 'Cost of Goods Sold', AccountType::Expense, '5000', true, 'cost_of_goods_sold'],
        // Where the six inventory adjustment types land: damaged, expired, lost,
        // count variance. They were built as the discriminator for exactly this.
        ['5200', 'Inventory Write-offs and Shrinkage', AccountType::Expense, '5000', true, 'inventory_write_off'],
        ['5300', 'Freight and Carriage Inward', AccountType::Expense, '5000', true, null],
        // ⚠ D-SB-2. The gap between the price a purchase order agreed and the
        // price the supplier actually invoiced. It sits under Cost of Sales
        // rather than Operating Expenses so that a favourable variance reduces
        // the cost of sales it belongs to, instead of appearing as negative
        // overhead somewhere unrelated.
        // ⚠⚠ A FAVOURABLE VARIANCE IS A CREDIT BALANCE ON AN EXPENSE ACCOUNT.
        // That is correct for a variance account and looks like an error until
        // somebody knows what it is, so the accountant is told once.
        ['5400', 'Purchase Price Variance', AccountType::Expense, '5000', true, 'purchase_price_variance'],

        // ─── 6000 Operating Expenses ────────────────────────────────
        //
        // The accountant's Master Expense Chart of Accounts, 5 Sep 2026.
        // Seventeen groups from his document, plus two of ours the document
        // does not cover (depreciation, and the system accounts).
        //
        // ⚠⚠ EVERY GROUP HEADER IS NON-POSTABLE, AND THAT IS THE WHOLE
        // MECHANISM. `StatementGrouping::headerFor` walks up to the nearest
        // non-postable ancestor and prints it as the profit and loss section
        // heading, ordering sections by the header's code. Nothing else had to
        // be written for the statement to group itself.
        //
        // ⚠ The document numbers its groups 01.01 through 17.02. Those are a
        // document outline, never GL codes: this chart is four digits
        // throughout so lexicographic order is chart order.
        //
        // ⚠⚠ WHAT THE DOCUMENT FORBIDS AS AN EXPENSE (its section 18), so
        // none of it appears below: product purchase, raw material, packaging
        // material, fixed assets, employee advance, supplier advance and
        // internal bank transfer. Its section 11 keeps customer refunds and
        // sales discounts on 4200 and 4210 as contra-revenue. And 04.11
        // "Employee Reimbursement Expense" is deliberately absent: it is the
        // liability 2145, and an expense head of the same name would
        // double-count every claim.
        //
        // ⚠ Group 16 Business Communication is folded into Office and
        // Administration, on the document's own instruction that the same
        // expense must never exist in two places.
        ['6000', 'Operating Expenses', AccountType::Expense, null, false, null],
        ['6010', 'Travel and Conveyance', AccountType::Expense, '6000', false, null],
        ['6011', 'Conveyance', AccountType::Expense, '6010', true, 'fuel_and_conveyance'],
        ['6012', 'Inter-district Travel', AccountType::Expense, '6010', true, null],
        ['6013', 'Bus, Train, Launch and Air Fare', AccountType::Expense, '6010', true, null],
        ['6014', 'Hotel and Accommodation', AccountType::Expense, '6010', true, null],
        ['6015', 'Business Travel Food', AccountType::Expense, '6010', true, null],
        ['6016', 'Fuel and Vehicle Running', AccountType::Expense, '6010', true, null],
        ['6017', 'Parking and Toll', AccountType::Expense, '6010', true, null],
        ['6018', 'Business Meeting Travel', AccountType::Expense, '6010', true, null],
        ['6019', 'Travel and TA', AccountType::Expense, '6010', true, null],
        ['6050', 'Office and Administration', AccountType::Expense, '6000', false, null],
        ['6051', 'Office Rent', AccountType::Expense, '6050', true, null],
        ['6052', 'Electricity and Gas', AccountType::Expense, '6050', true, null],
        ['6053', 'Water', AccountType::Expense, '6050', true, null],
        ['6054', 'Internet', AccountType::Expense, '6050', true, null],
        ['6055', 'Mobile and Telephone', AccountType::Expense, '6050', true, null],
        ['6056', 'Software Subscription', AccountType::Expense, '6050', true, null],
        ['6057', 'ERP and Hosting', AccountType::Expense, '6050', true, null],
        ['6058', 'Office Stationery', AccountType::Expense, '6050', true, null],
        ['6059', 'Printing and Photocopy', AccountType::Expense, '6050', true, null],
        ['6060', 'Office Cleaning', AccountType::Expense, '6050', true, null],
        ['6061', 'Office Maintenance and Repair', AccountType::Expense, '6050', true, null],
        ['6062', 'Office Consumables', AccountType::Expense, '6050', true, null],
        ['6063', 'Office Small Equipment', AccountType::Expense, '6050', true, null],
        ['6064', 'Business Communication Service', AccountType::Expense, '6050', true, null],
        ['6090', 'Warehouse and Operations', AccountType::Expense, '6000', false, null],
        ['6091', 'Warehouse Rent', AccountType::Expense, '6090', true, null],
        ['6092', 'Warehouse Electricity', AccountType::Expense, '6090', true, null],
        ['6093', 'Warehouse Water', AccountType::Expense, '6090', true, null],
        ['6094', 'Warehouse Maintenance and Repair', AccountType::Expense, '6090', true, null],
        ['6095', 'Warehouse Labour', AccountType::Expense, '6090', true, null],
        ['6096', 'Loading and Unloading Labour', AccountType::Expense, '6090', true, null],
        ['6097', 'Product Handling Labour', AccountType::Expense, '6090', true, null],
        ['6098', 'Cleaning and Sanitation', AccountType::Expense, '6090', true, null],
        ['6099', 'Warehouse Consumables', AccountType::Expense, '6090', true, null],
        ['6130', 'Employee and Staff', AccountType::Expense, '6000', false, null],
        ['6131', 'Salaries and Wages', AccountType::Expense, '6130', true, 'salaries_expense'],
        ['6132', 'Bonus and Festival Allowance', AccountType::Expense, '6130', true, 'bonus_expense'],
        ['6133', 'Overtime', AccountType::Expense, '6130', true, 'overtime_expense'],
        ['6134', 'Leave Encashment', AccountType::Expense, '6130', true, 'leave_encashment'],
        ['6135', 'Employee Allowance', AccountType::Expense, '6130', true, null],
        ['6136', 'Mobile Allowance', AccountType::Expense, '6130', true, null],
        ['6137', 'Employee Training', AccountType::Expense, '6130', true, null],
        ['6138', 'Employee Welfare', AccountType::Expense, '6130', true, null],
        ['6139', 'Employee Refreshment', AccountType::Expense, '6130', true, null],
        ['6141', 'Employee Gift', AccountType::Expense, '6130', true, null],
        ['6170', 'Product Sourcing', AccountType::Expense, '6000', false, null],
        ['6171', 'Supplier Visit', AccountType::Expense, '6170', true, null],
        ['6172', 'Factory Visit', AccountType::Expense, '6170', true, null],
        ['6173', 'Market Visit', AccountType::Expense, '6170', true, null],
        ['6174', 'Product Sourcing Visit', AccountType::Expense, '6170', true, null],
        ['6175', 'Product Sample Sourcing', AccountType::Expense, '6170', true, null],
        ['6176', 'Supplier Meeting', AccountType::Expense, '6170', true, null],
        ['6177', 'Product Sourcing Miscellaneous', AccountType::Expense, '6170', true, null],
        ['6200', 'Packaging Operations', AccountType::Expense, '6000', false, null],
        ['6201', 'Packaging Labour', AccountType::Expense, '6200', true, null],
        ['6202', 'Packaging Printing', AccountType::Expense, '6200', true, null],
        ['6203', 'Packaging Design', AccountType::Expense, '6200', true, null],
        ['6204', 'Packaging Development', AccountType::Expense, '6200', true, null],
        ['6205', 'Packaging Testing', AccountType::Expense, '6200', true, null],
        ['6206', 'Packaging Sourcing', AccountType::Expense, '6200', true, null],
        ['6207', 'Packaging Machine Repair and Maintenance', AccountType::Expense, '6200', true, null],
        ['6208', 'Other Packaging Operating', AccountType::Expense, '6200', true, null],
        ['6209', 'Packaging Consumables', AccountType::Expense, '6200', true, null],
        ['6240', 'Marketing - Online', AccountType::Expense, '6000', false, null],
        ['6241', 'Facebook Advertising', AccountType::Expense, '6240', true, null],
        ['6242', 'Facebook Boosting', AccountType::Expense, '6240', true, null],
        ['6243', 'Google Advertising', AccountType::Expense, '6240', true, null],
        ['6244', 'Instagram Advertising', AccountType::Expense, '6240', true, null],
        ['6245', 'TikTok Advertising', AccountType::Expense, '6240', true, null],
        ['6246', 'YouTube Advertising', AccountType::Expense, '6240', true, null],
        ['6247', 'Other Online Advertising', AccountType::Expense, '6240', true, null],
        ['6248', 'Content Creator', AccountType::Expense, '6240', true, null],
        ['6249', 'Food Blogger Promotion', AccountType::Expense, '6240', true, null],
        ['6251', 'Influencer Marketing', AccountType::Expense, '6240', true, null],
        ['6252', 'Online Campaign', AccountType::Expense, '6240', true, null],
        ['6253', 'Website Promotion', AccountType::Expense, '6240', true, null],
        ['6254', 'Product Photography', AccountType::Expense, '6240', true, null],
        ['6256', 'Product Video and Content Production', AccountType::Expense, '6240', true, null],
        ['6257', 'Graphic Design', AccountType::Expense, '6240', true, null],
        ['6258', 'Digital Promotional Material', AccountType::Expense, '6240', true, null],
        ['6289', 'General Marketing and Advertising', AccountType::Expense, '6240', true, null],
        ['6290', 'Marketing - Offline', AccountType::Expense, '6000', false, null],
        ['6291', 'Leaflet Printing', AccountType::Expense, '6290', true, null],
        ['6292', 'Leaflet Distribution', AccountType::Expense, '6290', true, null],
        ['6293', 'Banner and Poster', AccountType::Expense, '6290', true, null],
        ['6294', 'Billboard', AccountType::Expense, '6290', true, null],
        ['6295', 'Miking Campaign', AccountType::Expense, '6290', true, null],
        ['6296', 'Vehicle Branding', AccountType::Expense, '6290', true, null],
        ['6297', 'Roadshow and Campaign', AccountType::Expense, '6290', true, null],
        ['6298', 'Product Sampling', AccountType::Expense, '6290', true, null],
        ['6299', 'Promotional Booth', AccountType::Expense, '6290', true, null],
        ['6301', 'Retail Promotion', AccountType::Expense, '6290', true, null],
        ['6302', 'Dealer and Shop Promotion', AccountType::Expense, '6290', true, null],
        ['6303', 'Offline Campaign', AccountType::Expense, '6290', true, null],
        ['6304', 'Other Offline Marketing', AccountType::Expense, '6290', true, null],
        ['6330', 'Gifts and Promotional', AccountType::Expense, '6000', false, null],
        ['6331', 'Customer Gift', AccountType::Expense, '6330', true, null],
        ['6332', 'Customer Free Product', AccountType::Expense, '6330', true, null],
        ['6333', 'Free Sample', AccountType::Expense, '6330', true, null],
        ['6334', 'Promotional Gift', AccountType::Expense, '6330', true, null],
        ['6335', 'Campaign Gift', AccountType::Expense, '6330', true, null],
        ['6336', 'Promotional Merchandise', AccountType::Expense, '6330', true, null],
        ['6337', 'Other Promotional Items', AccountType::Expense, '6330', true, null],
        ['6370', 'Courier and Delivery', AccountType::Expense, '6000', false, null],
        // ⚠ Slugged on 13 Sep 2026, when a courier's payout statement started
        // posting to them: what the courier charges to deliver, to bring a
        // refused parcel back, and its extra charges.
        ['6371', 'Courier Charge', AccountType::Expense, '6370', true, 'courier_charge'],
        ['6372', 'Delivery Charge', AccountType::Expense, '6370', true, null],
        ['6373', 'Free Delivery', AccountType::Expense, '6370', true, null],
        ['6374', 'Customer Return Delivery', AccountType::Expense, '6370', true, 'customer_return_delivery'],
        ['6375', 'Product Return Transport', AccountType::Expense, '6370', true, null],
        ['6376', 'Special Delivery', AccountType::Expense, '6370', true, null],
        ['6377', 'Courier Adjustment', AccountType::Expense, '6370', true, 'courier_adjustment'],
        // Joy, 13 September: the courier's cash-on-delivery fee, its own line.
        ['6378', 'COD Handling Charge', AccountType::Expense, '6370', true, 'cod_handling_charge'],
        ['6400', 'Customer Service and Sales Adjustment', AccountType::Expense, '6000', false, null],
        ['6401', 'Customer Compensation', AccountType::Expense, '6400', true, null],
        ['6402', 'Product Replacement', AccountType::Expense, '6400', true, null],
        ['6403', 'Replacement Delivery', AccountType::Expense, '6400', true, null],
        ['6404', 'Customer Service', AccountType::Expense, '6400', true, null],
        ['6405', 'Customer Complaint Resolution', AccountType::Expense, '6400', true, null],
        ['6430', 'Events and Hospitality', AccountType::Expense, '6000', false, null],
        ['6431', 'Official Event', AccountType::Expense, '6430', true, null],
        ['6432', 'Guest Lunch', AccountType::Expense, '6430', true, null],
        ['6433', 'Guest Refreshment', AccountType::Expense, '6430', true, null],
        ['6434', 'Guest Accommodation', AccountType::Expense, '6430', true, null],
        ['6435', 'Meeting Lunch and Refreshment', AccountType::Expense, '6430', true, null],
        ['6436', 'Staff Event', AccountType::Expense, '6430', true, null],
        ['6437', 'Religious and Official Program', AccountType::Expense, '6430', true, null],
        ['6438', 'Event Decoration', AccountType::Expense, '6430', true, null],
        ['6439', 'Event Venue', AccountType::Expense, '6430', true, null],
        ['6441', 'Event Transport', AccountType::Expense, '6430', true, null],
        ['6442', 'Event Gift', AccountType::Expense, '6430', true, null],
        ['6470', 'Financial and Payment Charges', AccountType::Expense, '6000', false, null],
        ['6471', 'Bank Charge', AccountType::Expense, '6470', true, null],
        ['6472', 'bKash Charge', AccountType::Expense, '6470', true, null],
        ['6473', 'Nagad Charge', AccountType::Expense, '6470', true, null],
        ['6474', 'Rocket Charge', AccountType::Expense, '6470', true, null],
        ['6475', 'Payment Gateway Charge', AccountType::Expense, '6470', true, 'payment_gateway_charge'],
        ['6476', 'Transaction Charge', AccountType::Expense, '6470', true, null],
        ['6477', 'Other Financial Charges', AccountType::Expense, '6470', true, null],
        ['6500', 'Licence, Testing and Professional', AccountType::Expense, '6000', false, null],
        ['6501', 'Trade Licence', AccountType::Expense, '6500', true, null],
        ['6502', 'BSTI and Certification', AccountType::Expense, '6500', true, null],
        ['6503', 'BSCIC and Registration', AccountType::Expense, '6500', true, null],
        ['6504', 'Sanitary and Premises Licence', AccountType::Expense, '6500', true, null],
        ['6505', 'Lab Testing', AccountType::Expense, '6500', true, null],
        ['6506', 'Professional Consultancy', AccountType::Expense, '6500', true, null],
        ['6507', 'Audit Fee', AccountType::Expense, '6500', true, null],
        ['6508', 'Legal Fee', AccountType::Expense, '6500', true, null],
        ['6509', 'Government Fee', AccountType::Expense, '6500', true, null],
        ['6510', 'Other Regulatory', AccountType::Expense, '6500', true, null],
        // The turnover-tax regime's charge for a period (VAT plan D5). A cost of
        // the seller, never a line on an invoice, so it is an expense.
        ['6511', 'Turnover Tax', AccountType::Expense, '6500', true, 'turnover_tax'],
        ['6550', 'Repair and Maintenance', AccountType::Expense, '6000', false, null],
        ['6551', 'Computer and IT Repair', AccountType::Expense, '6550', true, null],
        ['6552', 'Printer Repair', AccountType::Expense, '6550', true, null],
        ['6553', 'Machinery Repair', AccountType::Expense, '6550', true, null],
        ['6554', 'Electrical Repair', AccountType::Expense, '6550', true, null],
        ['6555', 'Furniture Repair', AccountType::Expense, '6550', true, null],
        ['6556', 'Other Repair and Maintenance', AccountType::Expense, '6550', true, null],
        ['6600', 'Other Approved Business Expense', AccountType::Expense, '6000', false, null],
        ['6601', 'Miscellaneous Approved Expense', AccountType::Expense, '6600', true, null],
        ['6602', 'Other Business Expense', AccountType::Expense, '6600', true, null],
        ['6700', 'Depreciation and Amortisation', AccountType::Expense, '6000', false, null],
        ['6710', 'Depreciation', AccountType::Expense, '6700', true, 'depreciation_expense'],
        ['6800', 'System Accounts', AccountType::Expense, '6000', false, null],
        ['6810', 'Rounding Differences', AccountType::Expense, '6800', true, 'rounding_difference'],
        // What a till was over or short by when its shift was counted and closed
        // (accountant meeting 19 Sep 2026, F5). A debit is cash that went missing.
        ['6812', 'Cash Over and Short', AccountType::Expense, '6800', true, 'cash_over_short'],
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
