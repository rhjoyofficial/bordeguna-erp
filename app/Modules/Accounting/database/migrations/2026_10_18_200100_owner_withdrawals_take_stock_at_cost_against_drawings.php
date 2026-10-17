<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner withdrawals (Phase 2, 17 Oct 2026, ChatGPT ruling Q2 "Proprietor
 * Moin" and Q3 "What about Moin", decisions D1, D2, D5, D8).
 *
 * Bor de Guna is a sole proprietorship: stock the proprietor takes for
 * personal use is a DRAWING, not a sale. Before this the only path was a
 * counter sale to a walk-in customer at a staff price, which booked revenue
 * and a receivable for goods the business gave its own owner.
 *
 *   owner_withdrawals        the document: the proprietor, the warehouse, the
 *                            day, draft / approved / posted, who authorised it
 *                            (a second person, or the proprietor with a note:
 *                            D2 says authorised and audited each time, no
 *                            monthly cap), the posted journal
 *   owner_withdrawal_lines   each product and quantity; at approval the FIFO
 *                            batch reserved for it, that batch's unit cost
 *                            (frozen), the line value at cost, and the
 *                            reference price (MRP, or the selling price in
 *                            force) with the difference, kept per line as
 *                            evidence for the tax adviser (D8), never booked
 *   businesses.proprietor_user_id
 *                            who the proprietor is, set in Settings > Company;
 *                            null falls back to the Owner-role user whose
 *                            employee is BDG-000001 (Support\Proprietor)
 *
 * ⚠ Money is integer paisa; quantities and unit costs DECIMAL(15,4) like every
 * stock table. Additive; MySQL 8 and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // OW-2026-27-0001, restarting each fiscal year (DocumentNumber).
            $table->string('number', 32);
            $table->foreignId('proprietor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            // The day the goods left: the posting date of the movements and the journal.
            $table->date('withdrawn_on');
            $table->text('note')->nullable();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->text('authorisation_note')->nullable();
            // True when the proprietor authorised his own withdrawal (with a note).
            $table->boolean('self_authorised')->default(false);
            // Set when a posting found the reserved batch gone or re-costed and
            // sent the document back to draft (ruling Q1: never silently recompute).
            $table->text('reapproval_reason')->nullable();
            $table->bigInteger('cost_total_paisa')->default(0);
            $table->bigInteger('reference_total_paisa')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'withdrawn_on']);
        });

        Schema::create('owner_withdrawal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_withdrawal_id')->constrained('owner_withdrawals')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            // Written at approval, cleared when the document goes back to draft.
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->bigInteger('cost_paisa')->default(0);
            // mrp | selling_price | none: what the reference price was taken from.
            $table->string('reference_source', 16)->nullable();
            $table->decimal('reference_price', 15, 4)->nullable();
            $table->bigInteger('reference_paisa')->nullable();
            $table->bigInteger('difference_paisa')->nullable();
            // Written at posting: the movement that took the stock out.
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('proprietor_user_id')->nullable()->after('signatory_title')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proprietor_user_id');
        });

        Schema::dropIfExists('owner_withdrawal_lines');
        Schema::dropIfExists('owner_withdrawals');
    }
};
