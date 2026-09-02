<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Performance phase 1 (Module 5, Slice 5). Goals with an owner and an
// outcome; ONE review per person per period. Nothing here touches pay.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->unsignedBigInteger('set_by');
            $table->timestamps();
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('employee_id');
            $table->string('period');
            $table->unsignedTinyInteger('rating');
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->unsignedBigInteger('reviewed_by');
            $table->timestamps();
            $table->unique(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('performance_goals');
    }
};
