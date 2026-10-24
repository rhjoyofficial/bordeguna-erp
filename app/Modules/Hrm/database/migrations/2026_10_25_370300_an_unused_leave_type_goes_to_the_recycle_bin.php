<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a leave type nobody ever
 * used (no leave request, no leave ledger entry, not a system destination)
 * can be deleted to the Recycle bin with a reason; the Owner restores it. A
 * used one is switched off instead (is_active), as before.
 *
 * The bin columns every Recyclable model carries. Soft delete only: the
 * leave ledger and the requests point at the type by id, and nothing is ever
 * purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();

            $table->foreign('deleted_by', 'leave_types_deleted_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropForeign('leave_types_deleted_by_fk');
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};
