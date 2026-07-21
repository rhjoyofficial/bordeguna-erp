<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            // A central Bor De Guna record (ORG-01) — deliberately NOT
            // business-scoped. Which business an employee belongs to is
            // expressed through effective-dated employee_assignments, so one
            // person can be assigned to a child business or held centrally, and
            // transfers keep history (ORG-07).
            $table->string('employee_code')->unique(); // immutable global code, BDG-000001
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // optional ERP login
            $table->string('first_name');
            $table->string('last_name');
            $table->string('employment_type');
            $table->string('status')->default('draft');
            $table->string('personal_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable(); // sensitive — restricted visibility later
            $table->date('date_of_birth')->nullable();
            $table->date('joining_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
