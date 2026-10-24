<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B3 (R60 to R63, Joy's Q1 = A, 25 Oct 2026): "Treat Employees
 * as Bank Accounts". A money account of the kind Employee cash ("Petty Cash -
 * Sifat") is held by one HR employee, funded by a normal transfer, pays
 * purchases and expenses, and may go below zero: a negative balance is what
 * the company owes him.
 *
 *   money_accounts.custodian_employee_id   who holds it
 *   1135 Cash with Employees               the group its heads sit under
 *                                          (113501 Petty Cash - Sifat - Bor de Guna ...)
 *
 * ⚠ The group is created only on a chart that has 1100 Current Assets and no
 * 1135; a 1135 that is something else stops the migration with a sentence
 * rather than guessing. The chart seeder plants the same group on a fresh
 * install. Nothing is backfilled: no employee cash account exists before this.
 *
 * ⚠ MySQL: codes are bound as STRINGS.
 */
return new class extends Migration
{
    private const CODE = '1135';

    private const SLUG = 'employee_cash_accounts';

    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->foreignId('custodian_employee_id')->nullable()->after('type')
                ->constrained('employees', 'id', 'money_accounts_custodian_fk')->restrictOnDelete();
        });

        $existing = DB::table('accounts')->where('code', self::CODE)->first();
        $holder = DB::table('accounts')->where('system_slug', self::SLUG)->first();

        if ($holder !== null) {
            return;
        }

        if ($existing !== null) {
            if ($existing->system_slug !== null || (bool) $existing->is_postable) {
                throw new RuntimeException('Account '.self::CODE." {$existing->name} already exists and is not the Cash with Employees group. Move it by hand before this migration runs.");
            }

            DB::table('accounts')->where('id', $existing->id)->update(['system_slug' => self::SLUG, 'updated_at' => now()]);

            return;
        }

        $parentId = DB::table('accounts')->where('code', '1100')->value('id');

        if ($parentId === null) {
            // A chart the seeder has not planted yet; the seeder plants the group.
            return;
        }

        DB::table('accounts')->insert([
            'code' => self::CODE,
            'name' => 'Cash with Employees',
            'type' => 'asset',
            'parent_id' => $parentId,
            'is_postable' => false,
            'system_slug' => self::SLUG,
            'is_active' => true,
            'note' => 'Company cash a staff member holds (petty cash). One head per employee cash account; a credit balance is what the company owes that employee.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->dropForeign('money_accounts_custodian_fk');
            $table->dropColumn('custodian_employee_id');
        });

        // The group goes only while nothing sits under it.
        $group = DB::table('accounts')->where('system_slug', self::SLUG)->first();

        if ($group !== null && ! DB::table('accounts')->where('parent_id', $group->id)->exists()) {
            DB::table('accounts')->where('id', $group->id)->delete();
        }
    }
};
