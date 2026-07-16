<?php

namespace App\Modules\Foundation\database\seeders;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 3 known Bor De Guna businesses, a Super Admin, one demo Owner
 * per business, and the per-business Owner/Manager/Staff roles (spatie teams).
 *
 * Per-business roles are deliberately re-created per business (not shared),
 * since spatie's teams feature scopes role uniqueness by [business_id, name].
 */
class FoundationSeeder extends Seeder
{
    private const ROLE_NAMES = ['Owner', 'Manager', 'Staff'];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@bordeguna-erp.test'],
            ['name' => 'Bor De Guna Super Admin', 'password' => 'password', 'is_super_admin' => true]
        );

        foreach ($this->businesses() as $slug => $name) {
            $business = Business::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);

            $registrar->setPermissionsTeamId($business->id);

            foreach (self::ROLE_NAMES as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'business_id' => $business->id, 'guard_name' => 'web']);
            }

            $owner = User::firstOrCreate(
                ['email' => "owner@{$slug}.bordeguna-erp.test"],
                ['name' => "{$name} Owner", 'password' => 'password']
            );

            if (! $owner->businesses()->where('businesses.id', $business->id)->exists()) {
                $owner->businesses()->attach($business->id);
            }

            $owner->assignRole('Owner');
        }

        $registrar->setPermissionsTeamId(null);
    }

    /**
     * @return array<string, string> slug => name
     */
    private function businesses(): array
    {
        return [
            'laladia' => 'LaLaDia',
            'bionic' => 'Bionic',
            'azmion' => 'Azmion',
        ];
    }
}
