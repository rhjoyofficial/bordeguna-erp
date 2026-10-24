<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3, CRM (24 Oct 2026; FINAL-PLAN 3.2 and 6.3).
 *
 *   leads, contacts   the Recycle bin: a lead or a phone book contact made by
 *                     mistake and never used goes to the bin with who and why;
 *                     only the Owner restores (no purge, ever: answer 1).
 *   leads             `converted_party_created` remembers whether the
 *                     conversion CREATED the customer or supplier, so Reverse
 *                     conversion knows whether to switch it off again or only
 *                     unlink one that existed before. NULL on conversions made
 *                     before today (the action then compares the times).
 *   crm_activities    the author's 24-hour Edit and Delete, and Correct after
 *                     that: the old activity stays, struck through, pointing
 *                     at the one that replaced it.
 *
 * ⚠ Nothing is dropped and nothing existing changes meaning: every new column
 * is nullable and empty on old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('converted_party_created')->nullable()->after('converted_at');
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();
        });

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->unsignedBigInteger('correction_of_id')->nullable()->index();
            $table->text('correction_reason')->nullable();
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropIndex(['correction_of_id']);
            $table->dropColumn(['edited_at', 'edited_by', 'corrected_at', 'corrected_by', 'correction_of_id', 'correction_reason', 'deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['converted_party_created', 'deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};
