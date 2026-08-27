<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W2: which business a user lands in was decided by `ORDER BY name`, so a
     * display string chose the tenant. Renaming a business silently relocated
     * everyone whose session had expired.
     *
     * This column states the intended landing business in data. It is NOT a
     * unique-per-table invariant on purpose: the resolver orders by
     * `is_default DESC, id ASC`, which stays deterministic when zero or several
     * rows are flagged. See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §11.3.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // ⚠ Adding the column alone would ship the "nothing is flagged" state to
        // production, where the id fallback lands everybody in LaLaDia (id 1) -
        // wrong in a new way, since Bor de Guna is id 4. Flag it here.
        // No-op on any installation that has no such row.
        DB::table('businesses')->where('slug', 'bor-de-guna')->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
