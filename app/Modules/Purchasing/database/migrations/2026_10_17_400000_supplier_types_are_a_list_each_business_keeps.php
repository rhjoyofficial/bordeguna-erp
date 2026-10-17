<?php

use App\Modules\Purchasing\Support\SupplierTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier types become a list each business keeps (Joy, 17 Oct 2026: he needs
 * more than the seven fixed ones). Purchases > Suppliers > Supplier types.
 *
 * ⚠⚠ NO DATA CHANGES. `suppliers.supplier_type` and
 * `withholding_rates.supplier_type` stay the KEY string they always were; this
 * only gives those keys a row to live in. Every business gets the seven types
 * it was already using, with the SAME keys, so every supplier and every TDS/VDS
 * rate keeps working untouched.
 *
 * ⚠ A key found on a supplier or a rate that is not one of the seven (the old
 * validation should have made that impossible, but an import or a hand edit is
 * not validation) gets a row too, named after itself, so nothing is left
 * pointing at a type the screen cannot show.
 *
 * Idempotent: unique on (business_id, key) with insertOrIgnore, so a type
 * somebody renamed or switched off stays as they left it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The value suppliers and withholding rates store. Frozen once used.
            $table->string('key', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'key']);
        });

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            SupplierTypes::seedFor((int) $businessId);

            $known = DB::table('supplier_types')->where('business_id', $businessId)->pluck('key')->all();
            $sort = count($known);

            $found = DB::table('suppliers')->where('business_id', $businessId)->whereNotNull('supplier_type')->distinct()->pluck('supplier_type')
                ->merge(DB::table('withholding_rates')->where('business_id', $businessId)->whereNotNull('supplier_type')->distinct()->pluck('supplier_type'))
                ->map(fn ($key) => (string) $key)
                ->filter(fn (string $key) => $key !== '' && ! in_array($key, $known, true))
                ->unique()
                ->values();

            foreach ($found as $key) {
                DB::table('supplier_types')->insertOrIgnore([
                    'business_id' => $businessId,
                    'key' => $key,
                    'name' => $key,
                    'is_active' => true,
                    'sort' => $sort++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The keys stay on suppliers and rates; only the list goes.
        Schema::dropIfExists('supplier_types');
    }
};
