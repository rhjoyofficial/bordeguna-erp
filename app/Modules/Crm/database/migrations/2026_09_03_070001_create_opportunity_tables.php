<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Opportunities + pipeline (CRM spec sections 7-8, phase 1). A deal being
// chased: belongs to a lead or a customer, one stage at a time. Won and
// Lost are OUTCOMES (status), never stages - the board never grows a
// column deals go to die in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('name');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('stage_id');
            $table->decimal('value', 14, 2)->default(0);
            $table->date('expected_close_on')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->string('next_action')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->unsignedBigInteger('lost_reason_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['business_id', 'status', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_pipeline_stages');
    }
};
