<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment, Receive, Contra and Journal vouchers (the accountant, 19 Sep 2026).
 *
 * ⚠⚠ ONE TABLE FOR FOUR TYPES, and that is the design. What was asked for -
 * draft, post, print, reverse, copy, attach, search - is identical across the
 * four; they differ only in how their lines become journal lines. Four tables
 * would be four copies of every feature, drifting.
 *
 * ⚠⚠ A VOUCHER IS NOT A SECOND LEDGER. A draft touches nothing. Posting builds
 * one ordinary journal through PostJournal (source = the voucher), under the
 * same period lock as every other document. `journal_id` is that journal;
 * reversal is an ordinary reversing journal. Plan:
 * VOUCHERS-AND-DAY-BOOK-PLAN-2026-09-19.md.
 *
 * ⚠ The number is taken AT POSTING, so a deleted draft leaves no gap in the
 * sequence an auditor reads. `voucher_sequences` is per business, per type, per
 * fiscal year - the same shape as `journal_sequences`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            // Null while a draft. Unique per business once it exists.
            $table->string('voucher_number', 30)->nullable();
            $table->date('voucher_date');

            // Payment: paid FROM. Receipt: received INTO. Contra: moved FROM.
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            // Contra only: moved TO.
            $table->foreignId('to_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();

            // Who was paid / who paid, as written on the voucher.
            $table->string('counterparty', 255)->nullable();
            $table->text('narration')->nullable();

            $table->string('instrument_type', 20)->nullable();
            $table->string('instrument_number', 60)->nullable();
            $table->date('instrument_date')->nullable();
            $table->string('instrument_bank', 120)->nullable();

            $table->bigInteger('total_minor')->default(0);

            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();

            // "Reverse and copy" / "Duplicate": which voucher this began as.
            $table->foreignId('copied_from_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'voucher_number']);
            $table->index(['business_id', 'type', 'voucher_date']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // debit | credit. Fixed by the type for payment (debit) and receipt
            // (credit); chosen per line on a journal voucher.
            $table->string('side', 6);
            $table->bigInteger('amount_minor');
            $table->string('narration', 255)->nullable();
            // Only on a party-enabled account (supplier or customer advances).
            $table->nullableMorphs('party');
            $table->timestamps();

            $table->unique(['voucher_id', 'line_no']);
        });

        Schema::create('voucher_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'type', 'fiscal_year']);
        });

        // ⚠ The two advance accounts become findable by slug, because a line on
        // them carries a party and the code must not hang on a CODE that the
        // master-chart renumbering has already shown can change. Only where the
        // row exists and nobody has given it a slug already.
        foreach (['1160' => 'supplier_advances', '2150' => 'customer_advances'] as $code => $slug) {
            if (! DB::table('accounts')->where('system_slug', $slug)->exists()) {
                DB::table('accounts')->where('code', $code)->whereNull('system_slug')->update(['system_slug' => $slug]);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('system_slug', ['supplier_advances', 'customer_advances'])->update(['system_slug' => null]);

        Schema::dropIfExists('voucher_sequences');
        Schema::dropIfExists('voucher_lines');
        Schema::dropIfExists('vouchers');
    }
};
