<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Onboarding / offboarding checklists (Module 5, Slice 4). Items are DATA
// (planted defaults, editable later), progress records who ticked what and
// when, per employee.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('kind');
            $table->string('title');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'kind', 'title']);
        });

        Schema::create('hr_checklist_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('item_id')->constrained('hr_checklist_items')->cascadeOnDelete();
            $table->timestamp('done_at');
            $table->unsignedBigInteger('done_by');
            $table->timestamps();
            $table->unique(['employee_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_checklist_progress');
        Schema::dropIfExists('hr_checklist_items');
    }
};
