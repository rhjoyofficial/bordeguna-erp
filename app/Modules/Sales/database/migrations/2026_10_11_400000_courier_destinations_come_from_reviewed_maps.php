<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier hub slice 2 (11 Oct 2026): the ERP never books to a guessed
 * destination. Pathao, left to read the address text itself, misrouted 6 of 11
 * real orders.
 *
 * ⚠ All four tables are PROVIDER-WIDE, never business-scoped: a courier's city
 * list and its price bands are the same whichever brand books through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The courier's own lists, synced read-only through an active account.
        Schema::create('courier_locations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('level', 10);
            $table->string('provider_location_id', 40);
            $table->string('parent_provider_id', 40)->nullable();
            $table->string('name', 191);
            $table->json('extra')->nullable();
            $table->timestamp('synced_at')->nullable();
            // When this row's own children were last fetched (resume marker).
            $table->timestamp('children_synced_at')->nullable();
            // Listed before, missing from the courier's latest list. A map that
            // points here no longer resolves.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'level', 'provider_location_id'], 'courier_locations_unique');
            $table->index(['provider', 'level', 'parent_provider_id'], 'courier_locations_parent');
        });

        Schema::create('courier_location_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->foreignId('courier_account_id')->constrained('courier_accounts')->cascadeOnDelete();
            $table->string('status', 10)->default('running');
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('cities')->default(0);
            $table->unsignedInteger('zones')->default(0);
            $table->unsignedInteger('areas')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });

        // Bangladesh's place -> the courier's ids, with where the answer came
        // from and who looked at it.
        Schema::create('courier_location_maps', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('district', 80);
            $table->string('thana', 120);
            // ⚠ '' means the whole thana. Not NULL: MySQL lets any number of
            // NULLs past a unique index, so two "whole thana" rows could exist.
            $table->string('area', 150)->default('');
            $table->string('provider_city_id', 40)->nullable();
            $table->string('provider_city_name', 191)->nullable();
            $table->string('provider_zone_id', 40)->nullable();
            $table->string('provider_zone_name', 191)->nullable();
            $table->string('provider_area_id', 40)->nullable();
            $table->string('provider_area_name', 191)->nullable();
            // applied = the resolver uses it; suggested = waits for a person.
            $table->string('status', 10);
            $table->string('source', 20);
            $table->string('confidence', 10);
            $table->json('candidates')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'district', 'thana', 'area'], 'courier_location_maps_unique');
            $table->index(['provider', 'status']);
        });

        // Each courier's OWN price bands (Pathao's suburbs are not CarryBee's
        // middle band, and neither is the storefront's).
        Schema::create('courier_zone_bands', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('code', 40);
            $table->string('name', 120);
            $table->json('districts')->nullable();
            // "District/Thana" entries; a thana rule beats a district rule.
            $table->json('thanas')->nullable();
            // The band for everywhere no rule names. Exactly one per provider.
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'code']);
        });

        $now = now();
        $band = fn (string $provider, string $code, string $name, array $districts, array $thanas, bool $default, int $sort) => [
            'provider' => $provider, 'code' => $code, 'name' => $name,
            'districts' => json_encode($districts), 'thanas' => json_encode($thanas),
            'is_default' => $default, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
        ];

        // Pathao Pricing Plan page (11 Oct 2026): "Suburbs = Gazipur, Keraniganj,
        // Narayanganj, Savar". ⚠ Keraniganj and Savar are THANAS of Dhaka
        // district, so they are thana rules that beat Dhaka's district rule.
        // CarryBee calculator (11 Oct 2026): Dhaka / Gazipur + Narayanganj /
        // every other district.
        DB::table('courier_zone_bands')->insert([
            $band('pathao', 'inside_dhaka', 'Inside Dhaka', ['Dhaka'], [], false, 10),
            $band('pathao', 'suburbs', 'Suburbs', ['Gazipur', 'Narayanganj'], ['Dhaka/Keraniganj', 'Dhaka/Savar'], false, 20),
            $band('pathao', 'outside_dhaka', 'Outside Dhaka', [], [], true, 30),
            $band('carrybee', 'dhaka', 'Dhaka', ['Dhaka'], [], false, 10),
            $band('carrybee', 'gazipur_narayanganj', 'Gazipur and Narayanganj', ['Gazipur', 'Narayanganj'], [], false, 20),
            $band('carrybee', 'outside_dhaka', 'Outside Dhaka', [], [], true, 30),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_zone_bands');
        Schema::dropIfExists('courier_location_maps');
        Schema::dropIfExists('courier_location_syncs');
        Schema::dropIfExists('courier_locations');
    }
};
