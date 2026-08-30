<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company pad carries a website and the ERP had nowhere to put it.
 *
 * Joy sent the printed letterhead on 30 August 2026: head office, corporate
 * office, phone, email and www.bordeguna.com. Every one of those had a home in
 * `businesses` except the last, so a document produced by the ERP was missing a
 * line the paper version has.
 *
 * ⚠ Nullable, like every other identity field. BusinessIdentity prints what is
 * set and nothing else - an invoice header is the one place a plausible-looking
 * default does real damage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('website')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('website');
        });
    }
};
