<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The webhook pairing guard (11 Oct 2026). Three times in one day a courier
 * answered "Validation error" when the owner pressed its connect button,
 * because the value the courier wants back in our reply header was not saved,
 * and nothing in the ERP said so.
 *
 * Each account now remembers its last pairing attempt (when, whether it worked,
 * and a short problem code: missing_echo or bad_signature) and when the last
 * authentic status update arrived. Needs attention and Settings > Couriers read
 * these columns.
 *
 * ⚠ Never a header value: only times, a yes/no and a code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            $table->timestamp('webhook_last_handshake_at')->nullable();
            $table->boolean('webhook_last_handshake_ok')->nullable();
            $table->string('webhook_last_handshake_problem', 30)->nullable();
            $table->timestamp('webhook_last_event_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_last_handshake_at',
                'webhook_last_handshake_ok',
                'webhook_last_handshake_problem',
                'webhook_last_event_at',
            ]);
        });
    }
};
