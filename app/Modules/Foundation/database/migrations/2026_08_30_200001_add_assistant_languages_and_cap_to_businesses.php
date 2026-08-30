<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: the settings screen offers many languages, exactly two
 * may be active, and those two become the toggle on the chat box.
 *
 * ⚠ Stored as json rather than two columns. "The active pair" is one decision,
 * and splitting it into `language_one` / `language_two` invites the state where
 * they are the same or one is null - neither of which a toggle can render.
 *
 * ⚠⚠ THE CAP IS IN THE SAME MIGRATION BECAUSE IT GUARDS THE SAME FEATURE. Every
 * message spends money on a metered key and nothing in the ERP could stop it;
 * an assistant switched on for nine people with no ceiling is an invoice waiting
 * to be discovered. NULL means no cap, which is the honest reading of "nobody
 * has set one" - the switch itself is already off by default, so this is a
 * second brake rather than the only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('assistant_languages')->nullable()->after('ai_assistant_enabled');
            $table->unsignedInteger('ai_monthly_message_cap')->nullable()->after('assistant_languages');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['assistant_languages', 'ai_monthly_message_cap']);
        });
    }
};
