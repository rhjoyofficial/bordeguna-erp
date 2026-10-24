<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): the things register can be put
 * right (FINAL-PLAN 3.5).
 *
 *   asset_items         Recycle bin columns (a never-used thing, Delete),
 *                       the disposal (Dispose / Undo disposal: method, date,
 *                       reason, and what the thing looked like before, so an
 *                       undo puts back exactly that), and the import batch
 *                       the thing came in with (Reverse import)
 *   asset_import_batches  one row per applied things sheet, with its reversal
 *   asset_movements     the import batch, a reversal's link to what it
 *                       reverses (UNIQUE: a double click never writes a
 *                       second reversal), and when, by whom and why the
 *                       original was reversed or a transfer cancelled
 *   asset_movement_lines  the thing's state just before the line moved it
 *   asset_counts        Cancel of an open count
 *   asset_maintenance_records  Reopen of a done record (the last reopen)
 *   asset_credentials   Recycle bin columns (a device login, Delete)
 *
 * ⚠ The tag stays reserved in the bin: the tag's UNIQUE index keeps the row,
 * and SaveAssetItem already reads trashed rows when it numbers a new tag.
 * Nothing is backfilled: no thing was ever deleted, disposed or reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
        });

        Schema::table('asset_items', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('created_by')->constrained('asset_import_batches')->restrictOnDelete();
            $table->date('disposed_on')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->string('disposal_method', 20)->nullable();
            $table->text('disposal_reason')->nullable();
            // What custody, place, status and holdings were before: the undo.
            $table->text('disposal_snapshot')->nullable();
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();

            $table->foreign('disposed_by', 'asset_items_disposed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by', 'asset_items_deleted_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('asset_movements', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('created_by')->constrained('asset_import_batches')->restrictOnDelete();
            // ⚠ UNIQUE: one reversal per movement, the database's last word.
            $table->unsignedBigInteger('reverses_movement_id')->nullable()->unique();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->foreign('reverses_movement_id', 'asset_mov_reverses_fk')->references('id')->on('asset_movements')->restrictOnDelete();
            $table->foreign('reversed_by', 'asset_mov_reversed_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        // What the thing looked like just before this line moved it (custody,
        // place, status, condition), so a reversal puts back exactly that and
        // never guesses. Hand-overs recorded before today have none, and are
        // past the 7-day window within a week.
        Schema::table('asset_movement_lines', function (Blueprint $table) {
            $table->text('before')->nullable();
        });

        Schema::table('asset_counts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->foreign('cancelled_by', 'asset_counts_cancelled_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('asset_maintenance_records', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->foreign('reopened_by', 'asset_mr_reopened_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('asset_credentials', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();

            $table->foreign('deleted_by', 'asset_credentials_deleted_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_credentials', function (Blueprint $table) {
            $table->dropForeign('asset_credentials_deleted_by_fk');
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::table('asset_maintenance_records', function (Blueprint $table) {
            $table->dropForeign('asset_mr_reopened_by_fk');
            $table->dropColumn(['reopened_at', 'reopened_by', 'reopen_reason']);
        });

        Schema::table('asset_counts', function (Blueprint $table) {
            $table->dropForeign('asset_counts_cancelled_by_fk');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
        });

        Schema::table('asset_movement_lines', function (Blueprint $table) {
            $table->dropColumn('before');
        });

        Schema::table('asset_movements', function (Blueprint $table) {
            $table->dropForeign('asset_mov_reverses_fk');
            $table->dropForeign('asset_mov_reversed_by_fk');
            $table->dropUnique(['reverses_movement_id']);
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['reverses_movement_id', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });

        Schema::table('asset_items', function (Blueprint $table) {
            $table->dropForeign('asset_items_disposed_by_fk');
            $table->dropForeign('asset_items_deleted_by_fk');
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['disposed_on', 'disposed_at', 'disposed_by', 'disposal_method', 'disposal_reason', 'disposal_snapshot', 'deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::dropIfExists('asset_import_batches');
    }
};
