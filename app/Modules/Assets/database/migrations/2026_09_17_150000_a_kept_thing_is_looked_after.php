<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset management slice A4 (plan §4e): what should be done to a thing and
 * when, and what was done.
 *
 * ⭐ A PLAN is a promise ("clean the printer head every month"); a RECORD is a
 * fact (a fault reported, a repair done). Doing a plan's work moves the plan's
 * next due date; nothing else does.
 *
 * ⚠ `remind_from` is the next due date less the reminder days, stored rather
 * than computed, so "what is due" is one date comparison on SQLite and MySQL
 * alike.
 *
 * ⛔ No money moves here (plan §2.10). A record carries what the work cost and
 * the expense voucher that paid it; the voucher is the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_items', function (Blueprint $table) {
            $table->date('service_ends_on')->nullable()->after('warranty_ends_on');
            $table->foreignId('service_party_id')->nullable()->after('service_ends_on')->constrained('asset_parties')->restrictOnDelete();
        });

        Schema::create('asset_maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_item_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('every_count');
            // day | week | month
            $table->string('every_unit', 6);
            $table->date('next_due_on');
            $table->unsignedSmallInteger('remind_days')->default(3);
            $table->date('remind_from');
            // Who does it: somebody with an ERP login, an outside party, or
            // neither (whoever keeps the register).
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('asset_parties')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('last_done_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'is_active', 'remind_from']);
        });

        Schema::create('asset_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignId('asset_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_maintenance_plan_id')->nullable()->constrained()->restrictOnDelete();
            // preventive | repair | inspection
            $table->string('kind', 12);
            // open | done | cancelled
            $table->string('state', 10)->default('open');
            $table->text('problem');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->date('reported_on');
            // Out of service: the thing was set Under repair when this opened,
            // and `status_before` is what closing puts back.
            $table->boolean('out_of_service')->default(false);
            $table->string('status_before', 24)->nullable();
            $table->date('done_on')->nullable();
            $table->text('work_done')->nullable();
            $table->string('condition_after', 12)->nullable();
            $table->foreignId('done_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('asset_parties')->restrictOnDelete();
            // ⚠ Seen and set only by whoever sees the financial register.
            $table->decimal('cost', 15, 4)->nullable();
            $table->foreignId('expense_voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'state']);
            $table->index(['asset_item_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_records');
        Schema::dropIfExists('asset_maintenance_plans');

        Schema::table('asset_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_party_id');
            $table->dropColumn('service_ends_on');
        });
    }
};
