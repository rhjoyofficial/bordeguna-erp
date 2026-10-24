<?php

namespace App\Modules\Sales\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Actions\CheckCustomerCredit;
use App\Modules\Sales\Actions\CustomerExposure;
use App\Modules\Sales\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Joy, 25 Oct 2026 (Q11 = A): a BLANK credit limit now means CASH ONLY for a
 * trade customer (shop, dealer, corporate). Customers who owe money today with
 * no limit typed are NOT changed or blocked silently: this lists them, per
 * business, with what they owe, so Joy decides one by one (type a limit on the
 * customer, or leave blank and take cash from now on).
 *
 * ⚠ READ-ONLY. It writes nothing, and prints the database it reads.
 */
class ListBlankCreditLimits extends Command
{
    protected $signature = 'sales:credit-blank-limits {--business= : Business slug or id (default: every business)}';

    protected $description = 'List trade customers with a blank credit limit who owe money today (read-only)';

    public function handle(CustomerExposure $exposure): int
    {
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $key) => $q->where(fn ($w) => $w->where('slug', $key)->orWhere('id', (int) $key)))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $rows = Customer::query()
                ->whereNull('credit_limit')
                ->orderBy('name')
                ->get()
                ->filter(fn (Customer $customer) => CheckCustomerCredit::blankMeansCashOnly($customer))
                ->map(fn (Customer $customer) => [$customer->id, $customer->name, $customer->type->value, $exposure->handle($customer)['total']])
                ->filter(fn (array $row) => (float) $row[3] > 0)
                ->values();

            $this->line("Business: <options=bold>{$business->name}</> (id {$business->id}): {$rows->count()} customer(s)");

            if ($rows->isNotEmpty()) {
                $this->table(['Id', 'Customer', 'Type', 'Owes now (Tk)'], $rows->all());
            }

            $total += $rows->count();
        }

        $this->line("In all: {$total} trade customer(s) with a blank limit who owe money today.");

        return self::SUCCESS;
    }
}
