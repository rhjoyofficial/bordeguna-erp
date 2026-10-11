<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier hub slice 8 (11 Oct 2026): the outcomes the ERP used to refuse.
 *
 * - consignments: how a difference between what was owed and what the courier
 *   collected was resolved (ChatGPT ruling 3: not every difference is a
 *   discount), with the reason; and, for a courier answer dated into a closed
 *   month, the day it was actually posted and why (risk 9).
 * - courier_door_discount_requests: a door discount above the confirming
 *   person's cap waits here, with the whole outcome, for the Owner.
 * - courier_loss_claims: a parcel the courier lost or damaged (ruling 5). One
 *   claim per parcel; the goods leave through an inventory adjustment, the
 *   compensation arrives on a statement or by bank.
 * - courier_statements: the day a statement dated into a closed month posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            // discount | price_correction | short_collection | rounding
            $table->string('door_resolution', 30)->nullable()->after('collected_amount');
            // What was owed less what was collected; negative when more came in.
            $table->decimal('door_difference', 15, 4)->nullable()->after('door_resolution');
            $table->string('door_reason', 500)->nullable()->after('door_difference');
            $table->foreignId('party_adjustment_id')->nullable()->after('sales_return_id')->constrained('party_adjustments')->restrictOnDelete();
            // The courier's date stays in outcome_on; the documents carry this.
            $table->date('posted_on')->nullable()->after('outcome_on');
            $table->string('cutoff_note', 255)->nullable()->after('posted_on');
        });

        Schema::create('courier_door_discount_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consignment_id')->constrained()->restrictOnDelete();
            // The outcome as it was entered: outcome, date, collected, lines, note.
            $table->json('payload');
            $table->decimal('amount_due', 15, 4);
            $table->decimal('collected_amount', 15, 4);
            $table->decimal('discount_amount', 15, 4);
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'consignment_id']);
        });

        Schema::create('courier_loss_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consignment_id')->unique()->constrained()->restrictOnDelete();
            // The courier, as the money account its balances sit under.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20); // lost | damaged
            $table->date('reported_on');
            $table->decimal('value_at_cost', 15, 4)->default(0);
            $table->decimal('claimed_amount', 15, 4)->default(0);
            $table->decimal('compensation_received', 15, 4)->default(0);
            $table->string('status', 20)->default('open'); // open | accepted | paid | rejected
            $table->text('notes')->nullable();
            $table->foreignId('sales_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('courier_statement_line_id')->nullable()->constrained()->nullOnDelete();
            // Paid outside a statement: where it landed.
            $table->foreignId('compensation_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->date('compensation_on')->nullable();
            $table->string('compensation_reference', 100)->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->date('posted_on')->nullable()->after('received_on');
            $table->string('cutoff_note', 255)->nullable()->after('posted_on');
        });
    }

    public function down(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropColumn(['posted_on', 'cutoff_note']);
        });

        Schema::dropIfExists('courier_loss_claims');
        Schema::dropIfExists('courier_door_discount_requests');

        Schema::table('consignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_adjustment_id');
            $table->dropColumn(['door_resolution', 'door_difference', 'door_reason', 'posted_on', 'cutoff_note']);
        });
    }
};
