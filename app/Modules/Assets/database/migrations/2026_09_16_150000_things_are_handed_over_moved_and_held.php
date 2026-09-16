<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management slice A2 (plan §4c): who has a thing, and every hand-over
 * that put it there.
 *
 * ⭐ A movement is a DOCUMENT and is never edited: a mistake is another
 * movement. The item's custody columns and the holdings table are what the
 * documents add up to, written in the same transaction, and a command rebuilds
 * them from the lines to prove it.
 *
 * ⚠ Custody and location are separate facts. An issue to an employee changes
 * custody and not the place; a transfer changes the place and clears custody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('organisation', 120)->nullable();
            $table->string('phone', 30)->nullable();
            // repair_shop | freelancer | other
            $table->string('kind', 20)->default('other');
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'name']);
        });

        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            // issue | return | send_out | receive_back | transfer
            $table->string('kind', 20);
            // dispatched | completed
            $table->string('status', 20)->default('completed');

            // Where or whom it came from, and went to. Exactly one side each.
            $table->foreignId('from_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('from_party_id')->nullable()->constrained('asset_parties')->restrictOnDelete();
            $table->foreignId('to_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('to_party_id')->nullable()->constrained('asset_parties')->restrictOnDelete();

            $table->date('moved_on');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('asset_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // The condition noted at the hand-over, if any.
            $table->string('condition', 12)->nullable();
            $table->timestamps();

            $table->index('asset_item_id');
        });

        // Where the pieces of a counted group are: at a place, with an employee,
        // or with an outside party. One row per holder per item.
        Schema::create('asset_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('asset_parties')->restrictOnDelete();
            // ⚠ "o12" / "w3" / "e5" / "p2": a unique key over nullable columns
            // would not stop two rows for the same holder, since NULLs never
            // collide in MySQL.
            $table->string('holder_key', 20);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['asset_item_id', 'holder_key']);
        });

        Schema::table('asset_items', function (Blueprint $table) {
            $table->foreignId('custodian_employee_id')->nullable()->after('area')->constrained('employees')->restrictOnDelete();
            $table->foreignId('custodian_party_id')->nullable()->after('custodian_employee_id')->constrained('asset_parties')->restrictOnDelete();
            $table->date('custody_since')->nullable()->after('custodian_party_id');
            // The employee said "yes, I have it" (self-service).
            $table->timestamp('acknowledged_at')->nullable()->after('custody_since');
            // A transfer dispatched and not yet received: the thing is at neither place.
            $table->foreignId('transit_movement_id')->nullable()->after('acknowledged_at')->constrained('asset_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transit_movement_id');
            $table->dropColumn(['custody_since', 'acknowledged_at']);
            $table->dropConstrainedForeignId('custodian_party_id');
            $table->dropConstrainedForeignId('custodian_employee_id');
        });

        Schema::dropIfExists('asset_holdings');
        Schema::dropIfExists('asset_movement_lines');
        Schema::dropIfExists('asset_movements');
        Schema::dropIfExists('asset_parties');
    }
};
