<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            // status/requested_by/reviewed_by/reviewed_at now live on the
            // generic approval_requests table (see Approvable concern) —
            // stock_requests keeps only its own domain fields.
            $table->dropIndex(['business_id', 'status']);
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['status', 'requested_by', 'reviewed_by', 'reviewed_at', 'reason_note']);

            $table->foreignId('reason_id')->after('quantity')->constrained()->restrictOnDelete();
            $table->text('note')->nullable()->after('reason_id');
            $table->foreignId('resulting_transfer_id')->nullable()->after('resulting_movement_id')
                ->constrained('stock_transfers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->text('reason_note')->nullable();
        });

        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropForeign(['reason_id']);
            $table->dropForeign(['resulting_transfer_id']);
            $table->dropColumn(['reason_id', 'note', 'resulting_transfer_id']);

            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->index(['business_id', 'status']);
        });
    }
};
