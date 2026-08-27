<?php

namespace App\Modules\Hrm\database\seeders;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Bor de Guna's real organisational structure (go-live).
 *
 * ⚠ **This is production master data, not demo data.** It is separated from
 * `InventoryDemoDataSeeder` for exactly that reason: demo data may be wiped,
 * this may not.
 *
 * **M0, resolved by Joy 2026-07-28: Bor de Guna is the legal company and the
 * employer.** Every employee belongs to it, every salary is paid by it, and
 * LaLaDia and Bionic are *brands it operates* rather than employing entities.
 * That makes C7c trivially satisfied — one employer means one salary structure
 * per person with no apportionment question — and it is why this seeder creates
 * a fourth business rather than distributing people across the existing three.
 *
 * ⚠ **Idempotent throughout.** Every row is `firstOrCreate`d on a natural key,
 * so it is safe to re-run against a live database. That matters more than usual
 * here: this seeder will be run again each time the structure grows.
 *
 * **What this deliberately does NOT create:**
 *
 * - **Employees.** Blocked on the salary component breakdown (finding F3) and
 *   on one unconfirmed reporting line. Seeding people without their salary
 *   structures would create records that payroll cannot process.
 * - **Shifts and schedule assignments.** Blocked on the shift-start
 *   interpretation (B5) and the break policy (B6) — see the org spec.
 * - **Warehouse staffing.** Confirmed by Joy as unnecessary: people move
 *   between Head Office and the warehouses as the work requires, arranged
 *   manually, and the business does not want that recorded. So the warehouses
 *   exist as locations, and every employee is assigned to Head Office.
 */
class BorDeGunaOrganisationSeeder extends Seeder
{
    private const SLUG = 'bor-de-guna';

    /** The three physical locations, confirmed 2026-07-28. */
    private const OFFICES = [
        'Head Office – Uttar Adabor',
        'Warehouse – Savar',
        'Warehouse – Mohammadpur',
    ];

    /**
     * The three teams. They are **departments** rather than sections because
     * that is the level a shift is assigned at — `ScheduleScope` resolves
     * most-specific-wins, so a department-scoped schedule means a new hire
     * inherits their team's hours with no backfill and no per-person row.
     */
    private const DEPARTMENTS = [
        'Main Team',
        'Retail Sales Team',
        'Office Assistant Team',
    ];

    /**
     * ⚠ "Lead" is a *reporting position*, not a designation — it is expressed
     * by other people reporting to that person through `supervisor_id`. So the
     * Software Lead's designation stays "Software Innovation and System
     * Engineer" and the Retail Lead's stays "Assistant Sales Manager", which is
     * what their contracts say.
     */
    private const DESIGNATIONS = [
        'Founder',
        'Deputy Managing Director',
        'Coordinator',
        'Software Innovation and System Engineer',
        'Assistant Sales Manager',
        'Retail Sales Officer',
        'Operation Support Assistant',
    ];

    /**
     * ⚠⚠ **The role set the seeded defaults did not provide.**
     *
     * Only Owner / Manager / Staff / Finance existed. WFL-08 recorded that "HR
     * Head and Management do not exist as roles", parked the permissions on
     * Owner, and said they should be bound to real roles through the Roles UI
     * later. Bor de Guna's real structure is that moment.
     *
     * **HR and Payroll are separated deliberately.** C5/ACL-02 treats salary as
     * the most restricted data in the system, and somebody who maintains
     * employee records and approves leave does not thereby need to see what
     * everyone earns. In a nine-person company one person may hold both — but
     * that must be a deliberate grant of two roles, never one role that quietly
     * bundles them.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLES = [
        // Employee records, attendance, leave, scheduling. NO salary, NO payroll.
        'HR' => [
            'hrm.employees.manage',
            'hrm.attendance.manage',
            'hrm.attendance.approve',
            'hrm.leave.manage',
            'hrm.leave.approve',
            'hrm.scheduling.manage',
        ],
        // "Money related things" — Joy's phrase for what the Coordinator covers.
        'Payroll' => [
            'hrm.payroll.manage',
            'hrm.salary.manage',
            'hrm.salary.view',
        ],
        // Authorised Management read under C5: sees salary, changes nothing.
        'Management' => [
            'hrm.salary.view',
        ],
    ];

    public function run(): void
    {
        $business = Business::firstOrCreate(
            ['slug' => self::SLUG],
            [
                'name' => 'Bor de Guna',
                'is_active' => true,
                // Correction C12. The application default is already Asia/Dhaka;
                // set explicitly here so the record states it rather than
                // inheriting a default that could later change.
                'timezone' => 'Asia/Dhaka',
                'currency' => 'BDT',
            ],
        );

        // W2: this installation's landing business. Set outside firstOrCreate so
        // it is corrected on an existing row too, and set directly rather than
        // mass-assigned because `is_default` is deliberately not fillable.
        if (! $business->is_default) {
            $business->is_default = true;
            $business->save();
        }

        // Office, Department and Designation are business-scoped, and a seeder
        // runs in the console where no request has set the context — the same
        // trap 3c found with queued jobs. Set it explicitly.
        app(BusinessContext::class)->set($business->id);

        $this->seedRoles($business);
        $this->seedOffices();
        $this->seedDepartments();
        $this->seedDesignations();
        $this->seedImplementationAdmin($business);
    }

    private function seedRoles(Business $business): void
    {
        // The four the platform already assumes, plus the three Bor de Guna's
        // structure needs. Created even where nobody holds them yet: an unheld
        // role costs nothing, and a workflow stage bound to a role that does
        // not exist cannot be configured at all.
        foreach (['Owner', 'Manager', 'Staff', 'Finance'] as $name) {
            Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);
        }

        // ⚠ Create the permissions before granting them. This seeder runs
        // BEFORE the module permission seeders (so that they, iterating
        // Business::all(), find Bor de Guna and grant its Owner/Manager/Finance
        // roles) — which means the names below may not exist yet. Same
        // firstOrCreate the module seeders use, so nothing is duplicated.
        foreach (collect(self::ROLES)->flatten()->unique() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'business_id' => $business->id, 'guard_name' => 'web']);

            // `syncPermissions` rather than `givePermissionTo`, so re-running
            // after a permission is *removed* from the list actually removes it.
            // An additive-only seeder silently accumulates grants nobody
            // reviewed, which is how least privilege quietly stops being least.
            $role->syncPermissions($permissions);
        }
    }

    private function seedOffices(): void
    {
        foreach (self::OFFICES as $name) {
            Office::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }

    private function seedDepartments(): void
    {
        // Every department hangs off Head Office. Confirmed by Joy: staff move
        // between the office and the warehouses as needed and the business does
        // not want that movement recorded, so the warehouses hold no team.
        $headOffice = Office::where('name', self::OFFICES[0])->firstOrFail();

        foreach (self::DEPARTMENTS as $name) {
            Department::firstOrCreate(
                ['name' => $name],
                ['office_id' => $headOffice->id, 'is_active' => true],
            );
        }
    }

    private function seedDesignations(): void
    {
        foreach (self::DESIGNATIONS as $name) {
            Designation::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }

    /**
     * The implementation administrator (Joy's request, 2026-07-28).
     *
     * A **named non-person account** with unrestricted access, so the ERP can be
     * configured and tested before real users are onboarded.
     *
     * ⚠⚠ **Three conditions this account is created under, and they are not
     * decoration:**
     *
     *  1. **Named, never shared.** Every action in this system is
     *     activity-logged against its causer. A login several people share
     *     makes that log worthless at exactly the moment somebody needs it.
     *  2. **Time-boxed.** An unrestricted account that outlives implementation
     *     is a standing risk. It should be disabled once real users hold their
     *     roles — go-live + 30 days is a reasonable marker.
     *  3. **⚠ Its password is NOT set here outside local development.** In any
     *     other environment this seeds a long random secret that nobody — not
     *     the seeder's author, not the log, not this file — ever knows, and the
     *     account is unusable until Joy sets the password himself. A production
     *     credential chosen by an implementer is a credential the implementer
     *     retains.
     */
    private function seedImplementationAdmin(Business $business): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'implementation@bordeguna.test'],
            [
                'name' => 'Implementation Administrator',
                'is_super_admin' => true,
                'password' => Hash::make(
                    app()->environment('local')
                        // Local development only: the same throwaway used by the
                        // other dev accounts, so the environment stays usable.
                        ? 'password'
                        // Anywhere else: unknowable. Must be reset before use.
                        : Str::random(48),
                ),
            ],
        );

        if (! $admin->businesses()->where('businesses.id', $business->id)->exists()) {
            $admin->businesses()->attach($business->id);
        }
    }
}
