<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Customer groups with branches (Joy, 26 Oct 2026): "I want all the customer
 * goes under the Group name Sangram NGO, then all customer under it, with
 * selectable branch name or can be null. same will for Tycoon."
 *
 *   price_group_branches   a branch of the organisation a price group stands
 *                          for (Sangram NGO > Kuakata), with the branch's own
 *                          address and phone. The name is unique in its group.
 *   customers.price_group_branch_id   the branch a customer belongs to, or
 *                          null. Always a branch of the customer's own group
 *                          (checked by every writer, not by the database).
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_group_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_group_id')->constrained('price_groups')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['price_group_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('price_group_branch_id')->nullable()->after('price_group_id')
                ->constrained('price_group_branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_group_branch_id');
        });

        Schema::dropIfExists('price_group_branches');
    }
};
