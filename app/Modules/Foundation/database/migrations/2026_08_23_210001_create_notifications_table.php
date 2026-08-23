<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's standard notifications table, so an approval can reach somebody
     * without sending them an email.
     *
     * ⚠⚠ WHY THIS EXISTS. Joy, 2026-08-23: "do not send mail to request
     * correction, ERP notification is enough." Until now
     * `PendingApprovalNotification::via()` returned `['mail']` and nothing else,
     * against a live SMTP host, so every leave request, loan, correction, stock
     * request and purchase order emailed its approver. There was no in-app
     * channel to switch to, which is why this is a migration and not a one-line
     * config change.
     *
     * ⚠ The shape is Laravel's own (`notifiable_type`/`notifiable_id`, uuid
     * primary key, json `data`, nullable `read_at`), deliberately unmodified.
     * A hand-rolled variant would work until the first package that expects the
     * standard one.
     *
     * ℹ NOT business-scoped. A notification belongs to a USER, and a user can
     * belong to several businesses; scoping the row would mean deciding which
     * business an approval "belongs" to for somebody who can see both. The
     * payload carries a url, and the target screen applies its own business
     * guard - the W3 stale-tab rule already covers landing in the wrong one.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The unread count runs on every page load through the shared
            // Inertia props, so it gets its own index rather than scanning.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
