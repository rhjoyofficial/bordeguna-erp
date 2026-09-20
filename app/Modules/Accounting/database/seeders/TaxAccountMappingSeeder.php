<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Where the VAT engine posts.
 *
 * ⚠ `sales.` and `purchasing.` on purpose: those prefixes make 2170 and 1185
 * subsystem-owned (SubsystemAccounts), so nobody "just adjusts" VAT with a
 * manual journal - the one figure a tax officer reconciles to the documents.
 * ⚠ `accounting.vat_settlement` is deliberately NOT owned: the amount owed to
 * NBR is paid from it by an ordinary payment voucher.
 *
 * Seeded for every business whether or not VAT is on. A mapping posts nothing
 * by existing; it only means the day the switch is turned, the accounts are
 * already agreed.
 */
class TaxAccountMappingSeeder extends Seeder
{
    /** @var array<string, string> mapping key => account slug */
    public const MAPPINGS = [
        'sales.output_vat' => 'output_vat_payable',
        'purchasing.input_vat' => 'input_vat_receivable',
        'accounting.vat_settlement' => 'vat_settlement',
        'accounting.turnover_tax_expense' => 'turnover_tax',
    ];

    public function run(): void
    {
        $from = Carbon::parse('2000-01-01');
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::MAPPINGS as $key => $slug) {
                $account = Account::bySlug($slug);

                if ($account !== null && AccountMapping::forKeyOn($key, Carbon::now()) === null) {
                    AccountMapping::assign($key, $account, $from, 'Seeded default');
                }
            }
        }

        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
