<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who captured a lead.
 *
 * ⚠ Until now only the activity log knew, and "how many shops did Rakib add this
 * week" is a question the ASM will ask on day one. It is also what makes the
 * automatic assignment of an officer's own lead auditable: the assignee can be
 * changed later, the creator never.
 *
 * ⚠ Nullable, with no backfill. Measured on production 15 Sep 2026: `leads`
 * holds 0 rows, so there is nothing to backfill, and any lead arriving by a path
 * with no signed-in person (a future web form or import) honestly has no
 * creator.
 *
 * ⚠ restrictOnDelete, like verified_by and archived_by: users are deactivated,
 * never deleted, and a lead must not quietly lose the name of who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('assigned_to_user_id')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
