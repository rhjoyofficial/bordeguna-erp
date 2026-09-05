<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket opened by an external system (HQ) carries the caller's idempotency key, so a retried
 * request finds the ticket it already created instead of opening a second one. Unique per business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->string('external_ref', 160)->nullable()->after('created_by');
            $table->unique(['business_id', 'external_ref'], 'crm_tickets_business_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->dropUnique('crm_tickets_business_external_ref_unique');
            $table->dropColumn('external_ref');
        });
    }
};
