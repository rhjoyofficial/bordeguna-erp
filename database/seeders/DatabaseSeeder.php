<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\database\seeders\FoundationSeeder;
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
        $this->call(InventoryReasonsSeeder::class);
        $this->call(InventoryDemoDataSeeder::class);
    }
}
