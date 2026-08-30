<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per question asked of the assistant.
 *
 * ⚠⚠ THIS IS WHAT MAKES THE CAP POSSIBLE AND THE READING AUDITABLE. Without a
 * record there is no way to count what a business has spent this month, and no
 * way to answer "what has the assistant been reading" - which is the question
 * somebody will eventually ask about a thing that can see business data.
 *
 * ⚠⚠ THE QUESTION IS STORED, THE ANSWER IS NOT. The answer can contain rows the
 * asker was entitled to see at that moment and may not be entitled to later;
 * keeping it would build a second copy of the data outside every permission
 * check that guarded the first. The tools used are recorded instead, which is
 * what an audit actually needs.
 *
 * ⚠ `tools_used` is a plain list of names - never the arguments. Those name
 * tables, and a log that records which table somebody queried is a log that
 * discloses the schema to whoever can read logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('question');
            $table->string('language', 8)->nullable();

            // Which tools ran, by name only.
            $table->json('tools_used')->nullable();

            // Whether the turn finished or was refused, so a run of refusals is
            // visible rather than being something people just complain about.
            $table->boolean('answered')->default(true);

            $table->timestamps();

            // The index the monthly count runs on.
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
