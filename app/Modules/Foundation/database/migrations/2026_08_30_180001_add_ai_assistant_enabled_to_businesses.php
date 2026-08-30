<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: the assistant becomes something an admin switches on and
 * off, and only people with permission see it at all.
 *
 * ⚠⚠ DEFAULT FALSE, WHICH CHANGES TODAY'S BEHAVIOUR ON PURPOSE. The floating
 * button is currently rendered on every page for every user, and the endpoint
 * behind it has NO permission check whatsoever - so anybody who can log in can
 * spend money on the API key. Defaulting this to true would preserve that. The
 * safe direction for a feature that bills per message is off until somebody
 * deliberately turns it on.
 *
 * ⚠ Per business, like every other setting here. Four businesses share this
 * installation and only one of them is paying for a key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('ai_assistant_enabled')->default(false)->after('document_font');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('ai_assistant_enabled');
        });
    }
};
