<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ⚠⚠ MYSQL WILL NOT DROP AN INDEX THAT BACKS A FOREIGN KEY (error 1553),
        // and this one does — which SQLite never notices.
        //
        // The creating migration declared `foreignId('business_id')->constrained()`
        // and then, separately, `index(['business_id', 'status'])`. MySQL saw a
        // usable index already leading with `business_id` and reused it for the
        // constraint rather than creating its own. So dropping the composite
        // index here leaves the foreign key with nothing behind it, and MySQL
        // refuses:
        //
        //   General error: 1553 Cannot drop index
        //   'stock_requests_business_id_status_index': needed in a foreign key
        //   constraint
        //
        // SQLite enforces no such rule, so the whole test suite passes on it and
        // this only ever appears against a real production database. It is
        // exactly the portability class the deployment plan called out, and it
        // was caught by running migrations on MySQL before go-live.
        //
        // Fix: release the constraint, drop the index, then re-declare the
        // constraint — MySQL then builds its own index for it. Guarded by driver
        // so SQLite's behaviour is byte-identical to before.
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('stock_requests', function (Blueprint $table) {
                $table->dropForeign(['business_id']);
            });
        }

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

        if ($isMysql) {
            Schema::table('stock_requests', function (Blueprint $table) {
                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            });
        }
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
