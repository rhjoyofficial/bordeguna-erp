<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer service tickets, phase 1 (CRM spec sections 20-21). Phone and
// counter complaints: the resolve-by is STAMPED at creation from the
// priority's SLA hours, so a later rule change never rewrites history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('ticket_number')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('caller_phone')->nullable();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('priority');
            $table->string('status')->default('open');
            $table->timestamp('resolve_by');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('crm_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->foreignId('ticket_id')->constrained('crm_tickets')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_ticket_replies');
        Schema::dropIfExists('crm_tickets');
    }
};
