<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sales targets + commission phase 1: CALCULATION ONLY. The screen shows
// what commission WOULD be; paying stays with payroll, by a human hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->date('month');
            $table->decimal('target_amount', 14, 2);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['business_id', 'user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
