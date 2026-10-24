<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): files and logins go to the
 * Recycle bin instead of being destroyed (FINAL-PLAN 3.5 and 6.3).
 *
 *   attachments  Recycle bin columns. The stored file stays on the disk while
 *                the row is in the bin (no purge, ever: section 9 answer 1);
 *                the download route cannot reach a trashed row.
 *   users        Recycle bin columns (Delete login, only one that never
 *                signed in), `first_signed_in_at` (written by the Login
 *                listener), and a pending email change the new address must
 *                confirm before it replaces the old one.
 *
 * ⚠⚠ `first_signed_in_at` BACKFILL IS EVIDENCE ONLY (FINAL-PLAN 6.6, item 10:
 * no invented history). Nothing recorded a sign-in before today, so an
 * existing login counts as signed in when anything shows it was used: a
 * session row, a remember token, an API token, an activity-log line it
 * caused, or the super admin flag. The date written is the day the login was
 * created (the traces carry no first date). A login with no trace stays
 * empty, so its email is still fixable by a user admin and it may still be
 * deleted. Being wrong in that direction is safe: nobody used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();

            $table->foreign('deleted_by', 'attachments_deleted_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('first_signed_in_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_token', 64)->nullable();
            $table->timestamp('pending_email_sent_at')->nullable();
            $table->unsignedBigInteger('pending_email_by')->nullable();
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();

            $table->foreign('deleted_by', 'users_deleted_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('pending_email_by', 'users_pending_email_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        $used = collect();

        if (Schema::hasTable('sessions')) {
            $used = $used->merge(DB::table('sessions')->whereNotNull('user_id')->distinct()->pluck('user_id'));
        }

        if (Schema::hasTable('personal_access_tokens')) {
            $used = $used->merge(DB::table('personal_access_tokens')->where('tokenable_type', User::class)->distinct()->pluck('tokenable_id'));
        }

        if (Schema::hasTable('activity_log')) {
            $used = $used->merge(DB::table('activity_log')->where('causer_type', User::class)->whereNotNull('causer_id')->distinct()->pluck('causer_id'));
        }

        $used = $used->merge(DB::table('users')->whereNotNull('remember_token')->where('remember_token', '!=', '')->pluck('id'))
            ->merge(DB::table('users')->where('is_super_admin', true)->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique();

        foreach ($used->chunk(200) as $ids) {
            DB::table('users')->whereIn('id', $ids->all())->whereNull('first_signed_in_at')->update(['first_signed_in_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_deleted_by_fk');
            $table->dropForeign('users_pending_email_by_fk');
            $table->dropColumn(['first_signed_in_at', 'pending_email', 'pending_email_token', 'pending_email_sent_at', 'pending_email_by', 'deleted_at', 'deleted_by', 'delete_reason']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign('attachments_deleted_by_fk');
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};
