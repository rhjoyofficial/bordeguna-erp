<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\database\seeders\FoundationSeeder;
use App\Modules\Hrm\database\seeders\AttendanceCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\BangladeshHolidayCalendarSeeder;
use App\Modules\Hrm\database\seeders\HrmChangeRequestWorkflowSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
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

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(FoundationSeeder::class);
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
        $this->call(InventoryReasonsSeeder::class);
        $this->call(InventoryDemoDataSeeder::class);
    }
}
