<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\database\seeders\FoundationSeeder;
use App\Modules\Hrm\database\seeders\AttendanceCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\BangladeshHolidayCalendarSeeder;
use App\Modules\Hrm\database\seeders\BorDeGunaAttendanceSeeder;
use App\Modules\Hrm\database\seeders\BorDeGunaOrganisationSeeder;
use App\Modules\Hrm\database\seeders\HrmChangeRequestWorkflowSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryDemoDataSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
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
            ['name' => 'Test User', 'password' => bcrypt('password')],
        );

        $this->call(FoundationSeeder::class);
        // ⚠⚠ Bor De Guna's real organisational structure — production master
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
        $this->call(ApprovalWorkflowSeeder::class);
        $this->call(HrmChangeRequestWorkflowSeeder::class);
        $this->call(BangladeshHolidayCalendarSeeder::class);
        $this->call(AttendancePolicySeeder::class);
        $this->call(AttendanceCorrectionWorkflowSeeder::class);
        $this->call(LeaveTypeSeeder::class);
        $this->call(LeaveWorkflowSeeder::class);
        $this->call(SalaryComponentSeeder::class);
        $this->call(PayrollWorkflowSeeder::class);
        $this->call(PayrollInputWorkflowSeeder::class);
        // ⚠ After AttendancePolicySeeder, BangladeshHolidayCalendarSeeder and
        // the shift/schedule tables exist: this replaces the generic POL-03
        // example policy with Bor De Guna's real ladder, and binds their teams
        // to their shifts.
        $this->call(BorDeGunaAttendanceSeeder::class);

        $this->call(InventoryReasonsSeeder::class);
        $this->call(InventoryDemoDataSeeder::class);
    }
}
