<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Informational only (e.g. "Net 30", "COD") — no credit-limit
            // enforcement or AP logic here, that's an Accounting-phase concern.
            $table->string('payment_terms')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });
    }
};
