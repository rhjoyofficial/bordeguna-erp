<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank statement import + matching (Module 2, Slice 3; spec §12).
 *
 * A statement line is the BANK'S claim about one movement; matching ties it
 * to the book's own journal line for the same money account. `amount` is
 * signed from the account's point of view: positive = money in. The book
 * side needs no new column - journal_lines already carries money_account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->date('txn_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 14, 2); // signed: positive = money in
            $table->string('match_status', 20)->default('unmatched'); // matched|unmatched|ignored
            $table->foreignId('matched_journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->index(['money_account_id', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
