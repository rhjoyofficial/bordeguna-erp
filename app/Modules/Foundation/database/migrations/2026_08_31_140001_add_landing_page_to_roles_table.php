<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based landing pages (C-LAND-2): which screen a holder of this role
 * sees first after login. A nullable KEY into the curated catalogue in
 * App\Support\LandingPages - never a URL - so a renamed page updates every
 * assignment with it and a typo cannot send anybody into a 403.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('landing_page', 40)->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('landing_page');
        });
    }
};
