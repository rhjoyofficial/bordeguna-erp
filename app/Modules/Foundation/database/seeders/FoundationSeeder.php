<?php

namespace App\Modules\Foundation\database\seeders;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\SeedCredentials;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 3 known Bor de Guna businesses, a Super Admin, one demo Owner
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
            // ⚠ Not a known password outside local/testing. This account holds
            // is_super_admin, and it was found live on the public production
            // system accepting the word "password" on 2026-07-29.
            [
                'name' => 'Bor de Guna Super Admin',
                'password' => SeedCredentials::demoPassword(),
                'is_super_admin' => true,
                'is_active' => SeedCredentials::demoActive(),
            ]
        );

        foreach ($this->businesses() as $slug => $name) {
            $business = Business::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);

            $registrar->setPermissionsTeamId($business->id);

            foreach (self::ROLE_NAMES as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'business_id' => $business->id, 'guard_name' => 'web']);
            }

            $owner = User::firstOrCreate(
                ['email' => "owner@{$slug}.bordeguna-erp.test"],
                [
                    'name' => "{$name} Owner",
                    'password' => SeedCredentials::demoPassword(),
                    'is_active' => SeedCredentials::demoActive(),
                ]
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
