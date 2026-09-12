<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What delivery costs, and which districts pay which rate.
 *
 * ⭐⭐ THE CHARGE IS DERIVED FROM THE DISTRICT AND THE CUSTOMER IS NEVER ASKED
 * FOR A BAND. LaLaDia asked twice - a courier district, and then a separate
 * "Delivery Zone" card that is really the price band - and NOTHING compared the
 * two answers, so somebody in Bagerhat could pick "Inside Dhaka" and pay 60
 * instead of 120 on every order. That was a real money leak for months.
 *
 * ⚠ The fix there was to derive the band and refuse a disagreeing pair. Here
 * there is nothing to disagree with, because THE CLIENT NEVER STATES A BAND OR A
 * CHARGE AT ALL - it states a district, and the shop prices it. The absence is
 * the enforcement, the same way `store_products` holds no price column.
 *
 * ⚠ Per STORE, not per business. The architecture document puts payment methods,
 * shipping rules and courier on the Store, because two shops on one ERP may ship
 * from different places and agree different rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            $table->string('name', 100);

            // ⚠ Decimal, never float. Money that a float has touched is money
            // that eventually disagrees with itself by a paisa.
            $table->decimal('charge', 12, 2);

            // ⭐ The catch-all: what a district nobody has priced costs. Exactly
            // one per store, which the application enforces on write.
            //
            // ⚠ A store with NO default cannot quote at all, and that is
            // deliberate. The alternative is charging zero for an unpriced
            // district, which is a shop silently paying for delivery it never
            // agreed to - free is a decision somebody makes, not a fallback.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Two bands called "Inside Dhaka" on one shop is a screen nobody can
            // read and a district mapping nobody can audit.
            $table->unique(['store_id', 'name']);
            $table->index(['store_id', 'is_default']);
        });

        Schema::create('district_shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            // ⚠ The DATASET'S OWN SPELLING, resolved through BdGeo before it is
            // written. A courier's spelling stored here would make the shop's
            // pricing depend on a courier's vocabulary, which is exactly what
            // owning the geography was meant to end.
            $table->string('district', 100);

            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->timestamps();

            // ⭐ One row per district per store, so "what does this district
            // cost" is a single indexed lookup that cannot answer twice.
            $table->unique(['store_id', 'district']);
        });

        Schema::table('carts', function (Blueprint $table) {
            // ⚠⚠ THE CART STORES THE DISTRICT, NEVER THE CHARGE - the same rule
            // slice 2.1 applied to prices. A stored charge is one that can go
            // stale the moment somebody edits the rate, and a basket quoting a
            // number the shop no longer charges is worse than no quote at all.
            $table->string('delivery_district', 100)->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('delivery_district');
        });

        Schema::dropIfExists('district_shipping_zones');
        Schema::dropIfExists('shipping_zones');
    }
};
