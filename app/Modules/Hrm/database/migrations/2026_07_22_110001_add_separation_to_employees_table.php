<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Set together when status becomes Separated. separation_date is the
            // last working date payroll prorates to (PAY-06).
            $table->string('separation_type')->nullable()->after('status');
            $table->date('separation_date')->nullable()->after('separation_type');
            $table->text('separation_reason')->nullable()->after('separation_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['separation_type', 'separation_date', 'separation_reason']);
        });
    }
};
