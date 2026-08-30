<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: "make a system so that I can integrate any provider just
 * by adding the variables".
 *
 * ⚠⚠ WIRING IS NOT A SECRET, AND SECRETS ARE NOT WIRING. `credentials` is
 * encrypted and write-only: it holds the api key, the password, the token. This
 * column holds the endpoint, the field names and the success rule - things
 * somebody must be able to READ BACK and correct when a provider changes its
 * API. Putting the endpoint in the encrypted bag would mean nobody could ever
 * see what the system was calling, which is the one thing you need when a send
 * silently fails.
 *
 * ⚠ Plain json, deliberately. There is nothing here worth encrypting and
 * everything here worth showing on the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_providers', function (Blueprint $table) {
            $table->json('config')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('message_providers', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
