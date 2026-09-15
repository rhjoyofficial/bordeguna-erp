<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the ERP knows about a retailer, a distributor or a supplier that it does
 * not need to know about a consumer.
 *
 * CRM lead slice 2c (build plan 15 Sep 2026), every field from Joy's interview
 * answers in CRM-LEAD-PROFILES-DESIGN-2026-09-14.md. Nothing here is a guess:
 *
 * ⭐ `leads.potential` Low / Medium / High, the officer's estimate (answer 5),
 *    and `leads.importance` A / B / C, set by the officer and changeable by the
 *    ASM, empty meaning Unrated (answer 6). Shop-shaped leads only, and never
 *    merged with the purchase-value band, which will be DERIVED from sales.
 * ⭐ Retailer: shop type from an editable list (answer 4), who supplies them
 *    now, competitor brands from a list the company keeps (answer 7), and
 *    whether they want credit - yes, no, or not asked (answer 9). No credit
 *    limit on a lead: the limit is set at conversion.
 * ⭐ Distributor: business nature, other companies distributed, warehouses,
 *    vehicles and sales staff as the distributor's CLAIM (answer 17: recorded,
 *    no minimums), and the thanas it says it covers (answer 12: a claim, no
 *    exclusivity). No turnover band (plan D9) and no deposit (answer 11).
 * ⭐ Supplier: supplier type from an editable list (answer 14), what it supplies,
 *    and where it comes from.
 *
 * ⚠ ONE lookup table for the three editable lists (plan D15), keyed by `list`,
 * rather than three tables with one column each. Lost reasons stay in `reasons`
 * where `leads.lost_reason_id` already points.
 *
 * ⚠ One profile row per lead per type, UNIQUE on lead_id. A lead corrected from
 * retailer to distributor keeps its retailer row as history; the screen reads
 * the row for the current type.
 *
 * ⚠ Every table carries `business_id` (the tenant scope and the sandbox
 * teardown both depend on it). Explicit short index names for MySQL's 64
 * characters; rollback drops the dependants first.
 *
 * Measured on production 15 Sep 2026: 0 leads, so all of this is additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('list', 30);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'list', 'name'], 'crm_lookups_biz_list_name_uq');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('potential', 10)->nullable()->after('status');
            $table->char('importance', 1)->nullable()->after('potential');
        });

        Schema::create('retailer_lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique('retailer_profiles_lead_uq')->constrained()->restrictOnDelete();
            $table->foreignId('shop_type_id')->nullable()->constrained('crm_lookups')->restrictOnDelete();
            $table->string('current_supplier', 160)->nullable();
            // NULL = not asked. A missing answer is not a "no".
            $table->boolean('wants_credit')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_competitor_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('crm_lookup_id')->constrained('crm_lookups')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'crm_lookup_id'], 'lead_competitor_brands_uq');
        });

        Schema::create('distributor_lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique('distributor_profiles_lead_uq')->constrained()->restrictOnDelete();
            $table->string('business_nature', 160)->nullable();
            $table->text('other_companies')->nullable();
            $table->unsignedSmallInteger('warehouse_count')->nullable();
            $table->string('warehouse_ownership', 10)->nullable();
            $table->unsignedSmallInteger('vehicle_count')->nullable();
            $table->unsignedSmallInteger('sales_staff_count')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_coverage_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->string('district', 60);
            $table->string('thana', 80);
            $table->timestamps();

            $table->unique(['lead_id', 'district', 'thana'], 'lead_coverage_areas_uq');
        });

        Schema::create('supplier_lead_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->unique('supplier_profiles_lead_uq')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_type_id')->nullable()->constrained('crm_lookups')->restrictOnDelete();
            $table->string('supply_categories')->nullable();
            $table->string('origin_district', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_lead_profiles');
        Schema::dropIfExists('lead_coverage_areas');
        Schema::dropIfExists('distributor_lead_profiles');
        Schema::dropIfExists('lead_competitor_brands');
        Schema::dropIfExists('retailer_lead_profiles');

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['potential', 'importance']);
        });

        Schema::dropIfExists('crm_lookups');
    }
};
