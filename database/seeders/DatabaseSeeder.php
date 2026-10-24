<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\LoanWorkflowSeeder;
use App\Modules\Accounting\database\seeders\VoucherWorkflowSeeder;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Crm\database\seeders\CrmLookupSeeder;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\database\seeders\MessageCampaignWorkflowSeeder;
use App\Modules\Foundation\database\seeders\ActionReasonsSeeder;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\database\seeders\CorrectionWorkflowSeeder;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\database\seeders\FoundationSeeder;
use App\Modules\Foundation\Support\SeedCredentials;
use App\Modules\Hrm\database\seeders\AttendanceCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\BangladeshHolidayCalendarSeeder;
use App\Modules\Hrm\database\seeders\BorDeGunaAttendanceSeeder;
use App\Modules\Hrm\database\seeders\BorDeGunaEmployeeSeeder;
use App\Modules\Hrm\database\seeders\BorDeGunaOrganisationSeeder;
use App\Modules\Hrm\database\seeders\ExpenseClaimWorkflowSeeder;
use App\Modules\Hrm\database\seeders\HrCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\HrmChangeRequestWorkflowSeeder;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Inventory\database\seeders\CropWriteOffWorkflowSeeder;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryDemoDataSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Inventory\database\seeders\InventoryUnitsSeeder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // ⚠ `firstOrCreate`, not `factory()->create()`. Laravel's stock line
        // fails on a unique-email violation the second time the seeder runs,
        // which made the whole seed non-repeatable — and go-live configuration
        // re-runs it every time the organisation grows. Every other seeder in
        // this project is deliberately idempotent; this one line was not.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                // ⚠ Laravel's stock demo login. Harmless locally, an open door
                // on a public production box — it was found live and active on
                // 2026-07-29 accepting the word "password".
                'password' => bcrypt(SeedCredentials::demoPassword()),
                'is_active' => SeedCredentials::demoActive(),
            ],
        );

        $this->call(FoundationSeeder::class);
        // ⚠⚠ Bor de Guna's real organisational structure — production master
        // data, and it MUST run here, before every permission, workflow and
        // policy seeder below. All of those iterate Business::all(), so a
        // business created after them silently receives no permissions, no
        // holiday calendar, no attendance policy, no leave types and no salary
        // components. Seeded after them the first time, its Owner role came out
        // with zero permissions.
        $this->call(BorDeGunaOrganisationSeeder::class);

        $this->call(FoundationPermissionsSeeder::class);
        $this->call(HrmPermissionsSeeder::class);
        $this->call(InventoryPermissionsSeeder::class);
        $this->call(PurchasingPermissionsSeeder::class);
        // ⚠ Like every permission seeder here, it iterates Business::all(), so
        // it must run after BorDeGunaOrganisationSeeder has created the real
        // business and its Payroll and Finance roles.
        $this->call(AccountingPermissionsSeeder::class);
        // ⚠ Same ordering constraint as every permission seeder here: it walks
        // Business::all(), so it must run after the real business and its roles
        // exist.
        $this->call(SalesPermissionsSeeder::class);
        $this->call(CommercePermissionsSeeder::class);
        // ⚠⚠ ABSENT UNTIL 15 SEP 2026, found while planning CRM lead slice 2. The
        // CRM seeder was called only by SandboxSeeder, so the `db:seed --force`
        // that deploy/README.md names as THE way a permission reaches production
        // never touched CRM at all. Production's 11 CRM permissions got there by
        // some other route; the Sales Officer and Sales Lead roles would not have
        // got there at all, and nothing would have failed.
        $this->call(CrmPermissionsSeeder::class);
        // Joy's shop and supplier types, only into a list that is still empty.
        $this->call(CrmLookupSeeder::class);
        // ⚠ ABSENT UNTIL 16 SEP 2026, the CRM seeder's story again: the asset
        // permissions reached only the sandbox. Now with the Asset Keeper role
        // and the starting categories (only into a business that has none).
        $this->call(AssetsPermissionsSeeder::class);
        $this->call(AssetCategorySeeder::class);
        $this->call(ApprovalWorkflowSeeder::class);
        $this->call(HrmChangeRequestWorkflowSeeder::class);
        $this->call(BangladeshHolidayCalendarSeeder::class);
        $this->call(AttendancePolicySeeder::class);
        $this->call(AttendanceCorrectionWorkflowSeeder::class);
        $this->call(LeaveTypeSeeder::class);
        $this->call(LeaveWorkflowSeeder::class);
        // Corrections wave 3 (24 Oct 2026): HR cancel or undo, second person.
        $this->call(HrCorrectionWorkflowSeeder::class);
        $this->call(SalaryComponentSeeder::class);
        $this->call(PayrollWorkflowSeeder::class);
        $this->call(PayrollInputWorkflowSeeder::class);
        $this->call(ExpenseClaimWorkflowSeeder::class);
        // Second approval on large payments (29 Sep 2026), and the campaign
        // workflow that existed but was never called here.
        $this->call(VoucherWorkflowSeeder::class);
        // The Loan Register (14 Oct 2026): loan, amendment and write-off reach
        // the Owner; the loan-interest TDS rule is planted switched off.
        $this->call(LoanWorkflowSeeder::class);
        $this->call(MessageCampaignWorkflowSeeder::class);
        // Farm harvest (24 Oct 2026): a crop failure write-off reaches the Owner.
        $this->call(CropWriteOffWorkflowSeeder::class);
        // Edit, delete and correct, Phase 0 (17 Oct 2026): who approves a
        // correction, and the reason lists every correction dialog offers.
        $this->call(CorrectionWorkflowSeeder::class);
        $this->call(ActionReasonsSeeder::class);
        // ⚠ After AttendancePolicySeeder, BangladeshHolidayCalendarSeeder and
        // the shift/schedule tables exist: this replaces the generic POL-03
        // example policy with Bor de Guna's real ladder, and binds their teams
        // to their shifts.
        $this->call(BorDeGunaAttendanceSeeder::class);

        // ⚠ W1 migration: the nine real employees. Must run AFTER the
        // organisation, attendance and salary-component seeders — it needs
        // departments, designations and the `basic`/`ta_da` components to
        // already exist.
        $this->call(BorDeGunaEmployeeSeeder::class);

        $this->call(InventoryReasonsSeeder::class);
        // ⚠⚠ ABSENT UNTIL 21 SEP 2026. A unit is required on every product and
        // the only seeder that planted any was the demo one below, which skips
        // production - so a real business started with no units and the product
        // form was a dead end. Additive only, matched on aliases; BEFORE the
        // demo seeder so the two agree on the same rows.
        $this->call(InventoryUnitsSeeder::class);
        $this->call(InventoryDemoDataSeeder::class);

        // ⚠⚠ ABSENT UNTIL 21 SEP 2026, the CRM seeder's story again. Production
        // has its four groups per business because somebody ran this by hand;
        // a business created after that would have none, and a customer with
        // no group falls to RETAIL, which would not exist. Idempotent per code.
        $this->call(PriceGroupSeeder::class);

        // ⚠ Walks every business, like the permission seeders above, so it must
        // run after BorDeGunaOrganisationSeeder has created the real one.
        $this->call(WalkInCustomerSeeder::class);

        // ⚠ Installation-level, not per business: ONE chart, four sets of
        // balances (C-GL-1). It depends on no other seeder and nothing depends
        // on it yet, so it sits at the end where it is easy to see.
        $this->call(ChartOfAccountsSeeder::class);
        // ⚠ AFTER the chart, because it resolves accounts by system slug, and
        // after the businesses, because it walks every one of them.
        $this->call(PayrollAccountMappingSeeder::class);
        $this->call(InventoryAccountMappingSeeder::class);
        $this->call(AssetAccountMappingSeeder::class);
        $this->call(SalesAccountMappingSeeder::class);
        // Role dashboards (11 Oct 2026): AFTER every seeder that creates roles,
        // so each named role gets its default dashboard.
        $this->call(DashboardAssignmentSeeder::class);
    }
}
