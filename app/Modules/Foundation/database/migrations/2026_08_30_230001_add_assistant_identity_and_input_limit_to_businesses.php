<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: the assistant gets a name and a face, and the box gets a
 * ceiling on what one message may contain - all three set by an admin.
 *
 * ⚠⚠ THE NAME AND THE PICTURE ARE PER BUSINESS, NOT PER INSTALLATION. Four
 * businesses share this database and one of them may want a different name in
 * front of its people; a single global name would be one business's choice
 * imposed on the others. Same reasoning as the language pair beside it.
 *
 * ⚠ NULL means "use the built-in default" for all three, rather than a copy of
 * the default written into every row. A default stored as data is a default
 * that stops following the code the day somebody changes the code.
 *
 * ⚠⚠ THE CHARACTER LIMIT IS NOT DECORATION. Every character is billed, and the
 * 4000 the request validator allows is roughly two pages that somebody can
 * paste in by accident. The admin's number is the one the box enforces; the
 * validator's 4000 stays behind it as the ceiling no setting may exceed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('ai_assistant_name')->nullable()->after('ai_monthly_message_cap');
            $table->string('ai_assistant_avatar_path')->nullable()->after('ai_assistant_name');
            $table->unsignedSmallInteger('ai_message_char_limit')->nullable()->after('ai_assistant_avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['ai_assistant_name', 'ai_assistant_avatar_path', 'ai_message_char_limit']);
        });
    }
};
