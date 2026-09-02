<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Activity cadences (Module 4, Slice 3). Standing rhythms that create TASKS
// on the machinery that already exists - the queue and the overdue digest
// then carry them. People still do the talking.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_cadences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->string('trigger');
            $table->unsignedInteger('delay_days');
            $table->string('task_title');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_cadences');
    }
};
