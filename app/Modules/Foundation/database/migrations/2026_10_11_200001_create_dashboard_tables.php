<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role dashboards (11 Oct 2026).
 *
 * - dashboard_role_assignments: which dashboard a role lands on, per business.
 *   Explicit, not inferred from permissions (ChatGPT ruling 1): permissions say
 *   what somebody may open, this says who they are on the landing screen.
 * - dashboard_layouts: one person's order and hidden widgets on one dashboard.
 *   ⚠ Presentation only. Hiding a widget never grants or removes access.
 * - users.preferences: personal settings that follow the person across
 *   businesses (the dashboard sound settings first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->string('dashboard', 20);
            $table->timestamps();

            $table->unique('role_id');
            $table->index(['business_id', 'dashboard']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dashboard', 20);
            $table->json('widget_order')->nullable();
            $table->json('hidden')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id', 'dashboard']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });

        Schema::dropIfExists('dashboard_layouts');
        Schema::dropIfExists('dashboard_role_assignments');
    }
};
