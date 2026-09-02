<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CashFlowForecast;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 6 - the last of the module (spec §24): the cash-flow
 * FORECAST. Forward-looking, built ONLY from documents that already exist:
 * unpaid invoices due in, unpaid bills and standing costs due out, and the
 * last real payroll as a clearly-labelled estimate. Nothing is invented -
 * a week with no documents forecasts zero movement, honestly.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Flow Co', 'slug' => 'flow-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'FlowViewer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->viewer = User::factory()->create();
    $this->viewer->businesses()->attach($this->business->id);
    $this->viewer->assignRole('FlowViewer');

    // Opening cash: 50,000 sitting in the bank.
    $gl = Account::create(['code' => '1120', 'name' => 'Cash at Bank', 'type' => 'asset', 'is_postable' => true]);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => $gl->id,
        'currency' => 'BDT', 'is_active' => true,
    ]);
    $journal = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id,
        'journal_number' => 'JV-F-0001', 'journal_type' => 'manual',
        'journal_date' => now()->subDays(10)->toDateString(), 'memo' => 'Opening cash',
        'posted_by' => $this->viewer->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        'journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 1,
        'account_id' => $gl->id, 'amount_minor' => 5000000, 'money_account_id' => $this->bank->id,
        'created_at' => now(),
    ]);
});

it('projects the weeks ahead from real documents only', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Store', 'type' => 'retail',
    ]);

    // 8,000 due IN next week.
    DB::table('sales_invoices')->insert([
        'business_id' => $this->business->id, 'customer_id' => $customer->id,
        'invoice_number' => 'INV-0001',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(9)->toDateString(),
        'subtotal' => 8000, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 8000,
        'created_by' => $this->viewer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // A standing cost of 4,900 due OUT in ~2 weeks.
    $account = Account::create(['code' => '6225', 'name' => 'Hosting', 'type' => 'expense', 'is_postable' => true]);
    $schedule = DB::table('recurring_expenses')->insertGetId([
        'business_id' => $this->business->id, 'name' => 'Hosting', 'amount' => 4900,
        'amount_varies' => 0, 'frequency' => 'monthly', 'day_of_month' => 28,
        'expense_account_id' => $account->id, 'money_account_id' => $this->bank->id,
        'start_date' => now()->subMonth()->toDateString(), 'status' => 'active', 'lead_days' => 5,
        'created_by' => $this->viewer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('recurring_expense_occurrences')->insert([
        'business_id' => $this->business->id, 'recurring_expense_id' => $schedule,
        'due_on' => now()->addDays(13)->toDateString(), 'period_label' => 'This month',
        'expected_amount' => 4900, 'status' => 'due', 'source_key' => 'f:1',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $forecast = CashFlowForecast::build(8);

    expect($forecast['opening_cash'])->toBe(50000.0);

    $weeks = collect($forecast['weeks']);
    expect($weeks)->toHaveCount(8)
        ->and($weeks->sum('in_invoices'))->toBe(8000.0)
        ->and($weeks->sum('out_recurring'))->toBe(4900.0)
        // The running balance ends where the documents say it must.
        ->and($weeks->last()['running'])->toBe(50000.0 + 8000.0 - 4900.0);
});

it('counts an overdue invoice as week-one money, not as never', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0002', 'name' => 'Late Store', 'type' => 'retail',
    ]);

    DB::table('sales_invoices')->insert([
        'business_id' => $this->business->id, 'customer_id' => $customer->id,
        'invoice_number' => 'INV-0009',
        'invoice_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(25)->toDateString(),
        'subtotal' => 3000, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 3000,
        'created_by' => $this->viewer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $weeks = collect(CashFlowForecast::build(4)['weeks']);

    expect($weeks->first()['in_invoices'])->toBe(3000.0)
        ->and($weeks->first()['overdue_in'])->toBe(3000.0);
});

it('renders the screen for accounting eyes with the negative week flagged', function () {
    $this->actingAs($this->viewer)->withSession(['current_business_id' => $this->business->id])
        ->get(route('accounting.cash-forecast'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/cash-forecast')
            ->where('opening_cash', 50000)
            ->has('weeks', 8));
});
