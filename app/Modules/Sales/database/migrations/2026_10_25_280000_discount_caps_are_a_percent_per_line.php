<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ DISCOUNT CAPS ARE A PERCENT PER LINE (Joy, 25 Oct 2026, Q12 = A, R89):
 * staff 2%, Manager 5%, Owner no cap, held on every line of an order, an
 * invoice and a counter bill (DiscountCap). Joy's answer replaces the caps of
 * 26 Sep 2026 (Manager 3%, others 1% or Tk 300), so every saved Finance policy
 * takes the new figures here; the Owner changes them later under Settings >
 * Finance policy. The Tk ceiling column stays and nothing reads it.
 *
 * ⚠ down() puts back the old figures, which is what they were before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_policies')) {
            return;
        }

        DB::table('finance_policies')->update([
            'manager_discount_cap_percent' => 5,
            'staff_discount_cap_percent' => 2,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_policies')) {
            return;
        }

        DB::table('finance_policies')->update([
            'manager_discount_cap_percent' => 3,
            'staff_discount_cap_percent' => 1,
        ]);
    }
};
