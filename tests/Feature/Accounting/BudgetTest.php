<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Reporting\Definitions\BudgetVsActualReport;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 2, Slice 2 (spec §21-§22): budgets, and the report that judges the
 * actuals against them.
 *
 * ⚠ The actual is read from journal_lines - the SAME rows the ledger prints -
 * never recomputed from documents. A budget report with its own idea of the
 * actual is a second source of truth arguing with the first.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Budget Co', 'slug' => 'budget-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.chart.manage', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Planner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view', 'accounting.chart.manage']);
    Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->planner = User::factory()->create();
    $this->planner->businesses()->attach($this->business->id);
    $this->planner->assignRole('Planner');

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole('Reader');

    $this->hosting = Account::firstOrCreate(['code' => '6225'], ['name' => 'Software and Hosting', 'type' => 'expense', 'is_postable' => true]);
    $this->rent = Account::create(['code' => '6210', 'name' => 'Rent', 'type' => 'expense', 'is_postable' => true]);
});

function asPlanner()
{
    return test()->actingAs(test()->planner)->withSession(['current_business_id' => test()->business->id]);
}

it('shows the budget screen with the fiscal year and the postable expense and income accounts', function () {
    asPlanner()
        ->get(route('accounting.budgets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/budgets')
            ->has('accounts')
            ->where('can_edit', true));

    test()->actingAs(test()->reader)->withSession(['current_business_id' => test()->business->id])
        ->get(route('accounting.budgets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
});

it('saves budget lines for a fiscal year and reads them back', function () {
    asPlanner()
        ->post(route('accounting.budgets.save'), [
            'fiscal_year' => 2026,
            'lines' => [
                ['account_id' => $this->hosting->id, 'month' => '2026-09-01', 'amount' => 4900],
                ['account_id' => $this->hosting->id, 'month' => '2026-10-01', 'amount' => 4900],
                ['account_id' => $this->rent->id, 'month' => '2026-09-01', 'amount' => 25000],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('budget_lines')->count())->toBe(3);

    // Saving again UPSERTS - a changed figure replaces, never duplicates.
    asPlanner()
        ->post(route('accounting.budgets.save'), [
            'fiscal_year' => 2026,
            'lines' => [
                ['account_id' => $this->hosting->id, 'month' => '2026-09-01', 'amount' => 5900],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('budget_lines')->count())->toBe(3)
        ->and((float) DB::table('budget_lines')
            ->where('account_id', $this->hosting->id)
            ->where('month', '2026-09-01')->value('amount'))->toBe(5900.0);
});

it('refuses the save to a role without chart management', function () {
    test()->actingAs(test()->reader)->withSession(['current_business_id' => test()->business->id])
        ->post(route('accounting.budgets.save'), ['fiscal_year' => 2026, 'lines' => []])
        ->assertForbidden();
});

it('judges the actuals against the plan, from the same rows the ledger prints', function () {
    $this->actingAs($this->planner);

    // The plan: hosting 4,900 in September.
    $budget = DB::table('budgets')->insertGetId([
        'business_id' => $this->business->id, 'fiscal_year' => 2026,
        'created_by' => $this->planner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('budget_lines')->insert([
        ['budget_id' => $budget, 'account_id' => $this->hosting->id, 'month' => '2026-09-01', 'amount' => 4900, 'created_at' => now(), 'updated_at' => now()],
        ['budget_id' => $budget, 'account_id' => $this->rent->id, 'month' => '2026-09-01', 'amount' => 25000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // The truth: hosting actually cost 6,000 (Dr, in paisa).
    $journal = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id,
        'journal_number' => 'JV-TEST-0001', 'journal_type' => 'manual',
        'journal_date' => '2026-09-15', 'memo' => 'Hosting bill',
        'posted_by' => $this->planner->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        'journal_id' => $journal, 'business_id' => $this->business->id, 'account_id' => $this->hosting->id, 'amount_minor' => 600000, 'line_no' => 1,
        'created_at' => now(),
    ]);

    $report = app(BudgetVsActualReport::class);
    $rows = $report->rows(new ReportParameters(['fiscal_year' => 2026]))->collect();

    $hosting = $rows->firstWhere('account_code', '6225');
    $rent = $rows->firstWhere('account_code', '6210');

    expect((float) $hosting['budget'])->toBe(4900.0)
        ->and((float) $hosting['actual'])->toBe(6000.0)
        ->and((float) $hosting['variance'])->toBe(-1100.0)      // overspent
        ->and((float) $rent['actual'])->toBe(0.0)
        ->and((float) $rent['variance'])->toBe(25000.0);        // nothing spent yet
});

it('is registered in the shared reporting engine', function () {
    $keys = app(\App\Modules\Hrm\Reporting\ReportRegistry::class)->all()->keys();

    expect($keys)->toContain('accounting_budget_vs_actual');
});
