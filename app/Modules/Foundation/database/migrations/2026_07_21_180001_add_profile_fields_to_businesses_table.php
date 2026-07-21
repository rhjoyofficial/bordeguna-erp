<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->text('address')->nullable()->after('legal_name');
            $table->string('phone')->nullable()->after('address');
            $table->string('email')->nullable()->after('phone');
            // Payroll and reporting read these: currency for money formatting,
            // timezone for attendance/cutoff timing. Bangladesh defaults.
            $table->string('currency', 3)->default('BDT')->after('email');
            $table->string('timezone')->default('Asia/Dhaka')->after('currency');
            // Financial-year anchor (Bangladesh runs July–June). The leave year is
            // the calendar year (LEV-02) — this is for future financial reporting.
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'address', 'phone', 'email', 'currency', 'timezone', 'fiscal_year_start_month']);
        });
    }
};
