<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * ⭐ Accounts in ONE sidebar group of seven sections, its 44 screens as tabs
 * (redesign round 3, 16 Oct 2026). It was 41 links in three groups plus the
 * Manager's own two-link group. Every screen keeps its address and the gate
 * its old link had, so nobody gains or loses a screen.
 *
 * The role checks are RUN: each role's real shared flags (from a page it
 * opens) go through the real accounts-sections.ts and lib/sections.ts in
 * tests/js/accounts-sections.harness.ts.
 */
function accountsHarness(array $calls): array
{
    $root = dirname(__DIR__, 3);
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the accounts harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/accounts-sections.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function accountsSource(string $path): string
{
    return file_get_contents(resource_path('js/'.$path));
}

it('lists every Accounts screen with the gate its old link had, url and gate on one line', function (string $url, string $gate) {
    expect(accountsSource('components/accounting/accounts-sections.ts'))->toContain("url: '{$url}', can: {$gate}");
})->with([
    // Overview
    ['/accounting/overview', 'view'],
    ['/accounting/cash-forecast', 'view'],
    // Vouchers
    ['/accounting/vouchers/payment', 'view'],
    ['/accounting/vouchers/receipt', 'view'],
    ['/accounting/vouchers/contra', 'view'],
    ['/accounting/vouchers/journal', 'view'],
    ['/accounting/other-income', 'view'],
    ['/accounting/adjustments', 'post'],
    ['/accounting/inter-business', 'post'],
    // Bank & cash
    ['/accounting/money-accounts', 'view'],
    ['/accounting/cash-and-bank', 'view'],
    ['/hrm/reports/cash_position', 'view'],
    ['/accounting/bank-statements', 'view'],
    ['/accounting/cheques', 'view'],
    ['/accounting/money-transfers', 'post'],
    // Ledgers
    ['/accounting/general-ledger', 'view'],
    ['/accounting/day-book', 'view'],
    ['/accounting/journals', 'view'],
    ['/accounting/customer-statement', 'view'],
    ['/accounting/supplier-statement', 'view'],
    ['/accounting/employee-statement', 'view'],
    ['/accounting/accounts', 'view'],
    // Reports
    ['/accounting/income-statement', 'view'],
    ['/accounting/balance-sheet', 'view'],
    ['/accounting/trial-balance', 'view'],
    ['/accounting/cash-flow', 'view'],
    ['/accounting/receivables-ageing', 'view'],
    ['/accounting/payables-ageing', 'view'],
    ['/accounting/inventory-valuation', 'view'],
    ['/accounting/cost-of-goods-sold', 'view'],
    ['/accounting/vat-return', 'view'],
    // Loans
    ['/accounting/loans', 'loans'],
    ['/accounting/loans/reports', 'loans'],
    ['/accounting/loans/accruals', 'loans'],
    ['/accounting/loans/tds-rules', 'loans'],
    ['/accounting/counterparties', 'parties'],
    // Month end & setup
    ['/accounting/period-close', 'view'],
    // Edit, delete and correct, Phase 0 (17 Oct 2026): Owner and Finance.
    ['/accounting/corrections', 'corrections'],
    ['/accounting/reconciliation', 'view'],
    ['/accounting/amortizations', 'view'],
    ['/accounting/budgets', 'view'],
    ['/accounting/dunning', 'view'],
    ['/accounting/opening-balances', 'post'],
    ['/accounting/vat-settings', '(f: SharedData) => f.canManageVat'],
    ['/accounting/finance-policy', 'policy'],
]);

it('defines the short gates on the flags the routes check', function () {
    expect(accountsSource('components/accounting/accounts-sections.ts'))
        ->toContain('const view = (f: SharedData) => f.canViewAccounting;')
        ->toContain('const post = (f: SharedData) => f.canPostJournal;')
        ->toContain('const loans = (f: SharedData) => f.canViewLoans;')
        ->toContain('const parties = (f: SharedData) => f.canViewCounterparties;')
        ->toContain('const policy = (f: SharedData) => f.canManageFinancePolicy;');

    [$count] = accountsHarness([['op' => 'count']]);

    expect($count)->toBe(['sections' => 7, 'tabs' => 45]);
});

it('draws Accounts from its sections: one group, the three old groups and the Manager group gone', function () {
    $sidebar = accountsSource('components/app-sidebar.tsx');

    expect(accountsSource('lib/sections.ts'))->toContain('...ACCOUNTS_SECTIONS')
        ->and($sidebar)->toContain('sectionNavItems(ACCOUNTS_SECTIONS, shared, t)')
        ->and($sidebar)->toContain("[{ label: 'Accounts', items: accountsItems }]")
        ->and(substr_count($sidebar, "label: 'Accounts'"))->toBe(1)
        ->and($sidebar)->not->toContain("label: 'Financial reports'")
        ->and($sidebar)->not->toContain("label: 'Accounts setup'")
        ->and($sidebar)->not->toContain("url: '/accounting/vouchers/payment'")
        ->and($sidebar)->not->toContain("url: '/accounting/trial-balance'");
});

describe('by role', function () {
    beforeEach(function () {
        $this->business = Business::create(['name' => 'Sections Co', 'slug' => 'sections-co']);
        TenantScope::enter($this->business->id);

        foreach (['Owner', 'Finance', 'Payroll', 'Manager', 'Staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
        }

        foreach ([FoundationPermissionsSeeder::class, SalesPermissionsSeeder::class, InventoryPermissionsSeeder::class, PurchasingPermissionsSeeder::class, AccountingPermissionsSeeder::class] as $seeder) {
            $this->seed($seeder);
        }

        // The shared flags a role's pages really carry.
        $this->flags = function (string $role): array {
            $user = User::factory()->create();
            $user->businesses()->attach($this->business->id);
            $user->assignRole(Role::where('name', $role)->where('business_id', $this->business->id)->firstOrFail());

            $props = [];
            $this->actingAs($user)->get('/settings/appearance')
                ->assertOk()
                ->assertInertia(function (Assert $page) use (&$props) {
                    $props = $page->toArray()['props'];
                });

            return $props;
        };
    });

    it('gives each role the Accounts rows and tabs its permissions open, and nothing more', function () {
        $owner = ($this->flags)('Owner');
        $finance = ($this->flags)('Finance');
        $payroll = ($this->flags)('Payroll');
        $manager = ($this->flags)('Manager');
        $staff = ($this->flags)('Staff');

        [$ownerNav, $ownerTabs, $financeNav, $financeTabs, $payrollNav, $payrollTabs, $managerNav, $managerTabs, $staffNav] = accountsHarness([
            ['op' => 'nav', 'flags' => $owner],
            ['op' => 'tabs', 'flags' => $owner],
            ['op' => 'nav', 'flags' => $finance],
            ['op' => 'tabs', 'flags' => $finance],
            ['op' => 'nav', 'flags' => $payroll],
            ['op' => 'tabs', 'flags' => $payroll],
            ['op' => 'nav', 'flags' => $manager],
            ['op' => 'tabs', 'flags' => $manager],
            ['op' => 'nav', 'flags' => $staff],
        ]);

        $count = fn (array $tabs) => array_map('count', $tabs);

        // Owner: all seven, all 45 (the Corrections report joined on 17 Oct 2026).
        expect(array_column($ownerNav, 'title'))->toBe(['Overview', 'Vouchers', 'Bank & cash', 'Ledgers', 'Reports', 'Loans', 'Month end & setup'])
            ->and(array_sum($count($ownerTabs)))->toBe(45);

        // Finance: no journal.post (Adjustments, Between businesses, Transfers,
        // Opening balances), no VAT settings, no Finance policy (ACC-005).
        expect(count($financeNav))->toBe(7)
            ->and($count($financeTabs))->toBe([
                'accounts-overview' => 2, 'accounts-vouchers' => 5, 'accounts-money' => 5, 'accounts-ledgers' => 7,
                'accounts-reports' => 9, 'accounts-loans' => 5, 'accounts-setup' => 6,
            ])
            ->and($financeTabs['accounts-vouchers'])->not->toContain('/accounting/adjustments')
            ->and($financeTabs['accounts-money'])->not->toContain('/accounting/money-transfers')
            ->and($financeTabs['accounts-setup'])->not->toContain('/accounting/vat-settings');

        // Payroll: reads the books, holds no loan or counterparty permission.
        expect(array_column($payrollNav, 'title'))->toBe(['Overview', 'Vouchers', 'Bank & cash', 'Ledgers', 'Reports', 'Month end & setup'])
            ->and($payrollTabs['accounts-loans'])->toBe([]);

        // Manager: one link, Loans, its five tabs; no Reports link (D4).
        expect($managerNav)->toBe([['title' => 'Loans', 'url' => '/accounting/loans']])
            ->and($managerTabs['accounts-loans'])->toHaveCount(5)
            ->and($managerTabs['accounts-reports'])->toBe([]);

        // Staff: no Accounts group at all.
        expect($staffNav)->toBe([]);
    });

    it('draws the right tab row on the addresses that need a rule', function () {
        $owner = ($this->flags)('Owner');
        $payroll = ($this->flags)('Payroll');
        $manager = ($this->flags)('Manager');

        [$receiptDoc, $plainDoc, $cashPosition, $newLoan, $ownerAgeing, $payrollAgeing, $ownerValuation, $managerValuation, $payrollSupplier] = accountsHarness([
            // A receipt voucher's own page names its tab (VoucherController@show).
            ['op' => 'row', 'path' => '/accounting/vouchers/doc/12', 'flags' => $owner + ['sectionTab' => '/accounting/vouchers/receipt']],
            ['op' => 'row', 'path' => '/accounting/vouchers/doc/12', 'flags' => $owner],
            // The longer prefix beats HRM's /hrm/reports (D6).
            ['op' => 'row', 'path' => '/hrm/reports/cash_position', 'flags' => $owner],
            ['op' => 'row', 'path' => '/accounting/loans/new', 'flags' => $owner],
            // Shared addresses: the earlier module's row for whoever may open its tab.
            ['op' => 'row', 'path' => '/accounting/receivables-ageing', 'flags' => $owner],
            ['op' => 'row', 'path' => '/accounting/receivables-ageing', 'flags' => $payroll],
            ['op' => 'row', 'path' => '/accounting/inventory-valuation', 'flags' => $owner],
            ['op' => 'row', 'path' => '/accounting/inventory-valuation', 'flags' => $manager],
            ['op' => 'row', 'path' => '/accounting/supplier-statement', 'flags' => $payroll],
        ]);

        expect($receiptDoc)->toBe(['section' => 'accounts-vouchers', 'current' => '/accounting/vouchers/receipt', 'tabs' => 7])
            ->and($plainDoc['current'])->toBe('/accounting/vouchers/payment')
            ->and($cashPosition)->toMatchArray(['section' => 'accounts-money', 'current' => '/hrm/reports/cash_position'])
            ->and($newLoan)->toMatchArray(['section' => 'accounts-loans', 'current' => '/accounting/loans'])
            ->and($ownerAgeing['section'])->toBe('sales-money')
            // Payroll has no Sales group (X2), so the Accounts row.
            ->and($payrollAgeing['section'])->toBe('accounts-reports')
            // The Inventory tab is hidden from ledger readers: the Owner gets Accounts.
            ->and($ownerValuation['section'])->toBe('accounts-reports')
            // The Manager keeps reading it as Inventory > Overview "Stock value".
            ->and($managerValuation)->toMatchArray(['section' => 'inventory-overview', 'current' => '/accounting/inventory-valuation'])
            // Purchases has no group gate: Payroll reads it under Purchases.
            ->and($payrollSupplier['section'])->toBe('purchases-bills');
    });

    it('still finds every old Accounts link by its old name in the header search', function () {
        $owner = ($this->flags)('Owner');
        $finance = ($this->flags)('Finance');

        [$transfers, $payment, $counterparties, $trial, $financeTransfers] = accountsHarness([
            ['op' => 'search', 'q' => 'transfer history', 'flags' => $owner],
            ['op' => 'search', 'q' => 'payment vouchers', 'flags' => $owner],
            ['op' => 'search', 'q' => 'counterparties', 'flags' => $owner],
            ['op' => 'search', 'q' => 'trial balance', 'flags' => $owner],
            ['op' => 'search', 'q' => 'transfer history', 'flags' => $finance],
        ]);

        expect($transfers)->toContain('/accounting/money-transfers')
            ->and($payment)->toContain('/accounting/vouchers/payment')
            ->and($counterparties)->toContain('/accounting/counterparties')
            ->and($trial)->toContain('/accounting/trial-balance')
            // Finance may not open Transfers, so search does not offer it.
            ->and($financeTransfers)->not->toContain('/accounting/money-transfers');
    });
});

it('writes no em dash into the Accounts sections, the Overview or the vouchers list', function () {
    foreach (['components/accounting/accounts-sections.ts', 'pages/accounting/finance-dashboard.tsx', 'pages/accounting/vouchers/index.tsx'] as $file) {
        expect(str_contains(accountsSource($file), "\u{2014}"))->toBeFalse($file.' holds an em dash');
    }
});
