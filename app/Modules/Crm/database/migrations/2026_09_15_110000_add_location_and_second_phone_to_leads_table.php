<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lead is, who to ask for, and the second number the shop answers on.
 *
 * CRM lead slice 2b (build plan 15 Sep 2026), from Joy's interview answers:
 *
 * ⭐ STRUCTURED LOCATION, NOT FREE TEXT. District and thana are the dataset's
 * own spellings (BdGeo), so "shops in Mirpur" is a query and not a guess at how
 * five officers spelled it. Area is optional because BdGeo's areas are post
 * office names ("Banani TSO"), and the market or bazaar does the real work of
 * finding a shop. The free-text `address` stays, for the house and road.
 *
 * ⭐ `contact_name` is the proprietor (answer 7). For a shop, `name` IS the
 * shop; the person behind the counter is a separate fact. It carries straight
 * to `customers.contact_name` and `suppliers.contact_name` at conversion.
 *
 * ⭐ The SECONDARY phone takes part in duplicate warnings but is never unique
 * (design doc, agreed item 6): a shop's second number is often the owner's
 * personal mobile, which another shop of his may also list.
 *
 * ⚠ Measured on production 15 Sep 2026: `leads` holds 0 rows. Every column is
 * nullable and additive; the "district and thana required for a shop" rule
 * (plan D8) lives in validation, where a lead from a future import can still
 * arrive incomplete and be finished by a person.
 *
 * ⚠ Short explicit index names: MySQL's 64-character limit, and the rollback
 * drops the indexes before the columns they cover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('name');
            $table->string('secondary_phone', 50)->nullable()->after('phone_normalized');
            $table->string('secondary_phone_normalized', 32)->nullable()->after('secondary_phone');

            $table->string('district', 60)->nullable()->after('address');
            $table->string('thana', 80)->nullable()->after('district');
            $table->string('area', 120)->nullable()->after('thana');
            $table->string('market', 160)->nullable()->after('area');
            $table->string('landmark')->nullable()->after('market');

            $table->index(['business_id', 'secondary_phone_normalized'], 'leads_biz_second_phone_idx');
            $table->index(['business_id', 'district', 'thana'], 'leads_biz_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_biz_second_phone_idx');
            $table->dropIndex('leads_biz_location_idx');

            $table->dropColumn([
                'contact_name', 'secondary_phone', 'secondary_phone_normalized',
                'district', 'thana', 'area', 'market', 'landmark',
            ]);
        });
    }
};
