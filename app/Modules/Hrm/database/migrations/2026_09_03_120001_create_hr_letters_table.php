<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HR letters (Module 5, Slice 2). Issued from live data, kept as a
// SNAPSHOT: a salary change next month must not rewrite a letter issued
// today. Print renders body_data, never the live record.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_letters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('reference_no')->unique();
            $table->string('type');
            $table->date('issued_on');
            $table->json('body_data');
            $table->unsignedBigInteger('issued_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_letters');
    }
};
