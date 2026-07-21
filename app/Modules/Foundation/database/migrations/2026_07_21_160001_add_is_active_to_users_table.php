<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A deactivated user keeps all history and business memberships but
            // cannot log in — the reversible "disable access" state the employment
            // separation flow (EMP-08) will drive later. Distinct from removing a
            // user from a single business (which is the business_user pivot).
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
