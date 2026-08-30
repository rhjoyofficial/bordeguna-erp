<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A personal to-do list, one per person.
 *
 * Joy, 30 August 2026: the assistant may create, read, update and delete the
 * asking employee's own notes and to-do list. Notes already existed. This did
 * not - `CrmTask` is CRM work hung off a lead or a customer, with a pipeline and
 * an owner who may not be the person who typed it. A private list of things to
 * do is a different thing and deserves its own table rather than a flag on that
 * one.
 *
 * ⚠⚠ `user_id` IS THE OWNER AND THE ONLY WAY IN. There is no sharing, no
 * assigning to somebody else and no "manager can see the team's list". Personal
 * means personal: an Owner holding every permission in the system reads their
 * own list and nobody else's. That is a decision, not an omission - a to-do list
 * people suspect their manager reads is a to-do list nobody writes honestly.
 *
 * ⚠ Also scoped by business. The same person can belong to more than one and
 * their list should not follow them between companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ Cascade: when a user is deleted their private list goes with
            // them. Nothing else should inherit it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('due_on')->nullable();

            // ⚠ A timestamp rather than a boolean, so "when did I finish that"
            // is answerable. Null means still to do.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_todos');
    }
};
