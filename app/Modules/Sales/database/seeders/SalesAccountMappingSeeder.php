<?php

namespace App\Modules\Sales\database\seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Where a sale lands in the chart, by default.
 *
 * ⚠⚠ `sales.revenue` is the ANCHOR. Its absence means a business can sell, move
 * stock and issue invoices with no journal at all - exactly as
 * `inventory.stock_on_hand` governs stock posting and `payroll.net_payable`
 * governs payroll.
 *
 * That is the standing rule this project settled on 3 August: an accounting
 * feature must never be able to stop an operational process. A shop whose
 * ledger is not configured yet must still be able to serve a customer.
 */
class SalesAccountMappingSeeder extends Seeder
{
    /** @var array<string, string> */
    private const MAPPINGS = [
        // The anchor. Present means sales post; absent means they do not.
        'sales.revenue' => 'sales_revenue',

        // What the customer owes. Party-tracked, so "who owes us" is answerable
        // without opening every invoice - the same subsidiary-ledger shape that
        // Accounts Payable already uses on the supplier side.
        'sales.accounts_receivable' => 'accounts_receivable',

        // ⚠ The cost of what was sold, released from GDNI when the invoice is
        // raised rather than when the goods left. This is the whole reason the
        // bridge account exists.
        'sales.cost_of_goods_sold' => 'cost_of_goods_sold',
        'sales.goods_delivered_not_invoiced' => 'goods_delivered_not_invoiced',

        // ⚠ Invoice-level discount only. A LINE discount never reaches here: it
        // reduces the line, and Sales Revenue is credited net, because a line
        // discount is a decision about what that product was worth to this
        // customer. An invoice-level discount is a decision about the whole
        // bill, which is why it is contra-revenue and visible separately.
        'sales.discounts_allowed' => 'discounts_allowed',

        // ⚠ GROSS, never netted into 4100 (C-SR-5): the return rate is a real
        // operational signal for a food business, and netting hides it.
        'sales.sales_returns' => 'sales_returns',

        // ⭐ A website order's agreed delivery charge, billed once on the first
        // invoice for that order (Joy, 13 September). Its own income line.
        'sales.delivery_income' => 'delivery_income',
    ];

    public function run(): void
    {
        $from = Carbon::parse('2000-01-01');
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::MAPPINGS as $key => $slug) {
                $account = Account::bySlug($slug);

                if ($account !== null) {
                    AccountMapping::assign($key, $account, $from, 'Seeded default');
                }
            }
        }

        // ⚠ Put the caller back. A seeder that walks every tenant and finishes
        // outside all of them evicts whatever called it.
        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
