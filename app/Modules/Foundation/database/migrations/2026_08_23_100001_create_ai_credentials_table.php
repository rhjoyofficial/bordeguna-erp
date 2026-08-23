<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an AI provider's API key lives, so Joy can add one himself.
     *
     * ⚠⚠ THE SECRET IS NEVER STORED IN PLAIN TEXT AND NEVER LEAVES THE SERVER.
     * `secret` holds Laravel-encrypted ciphertext (the `encrypted` cast on the
     * model, anchored to APP_KEY, which lives in the environment and not in the
     * database). The model hides the attribute, so no accidental `toArray()`,
     * Inertia prop or log line can carry it out. Joy's rule, 2026-08-23: the key
     * must not become visible in application data, logs, browser responses or
     * source code.
     *
     * ⚠ `hint` is the ONLY part of the key that is ever readable again - the
     * last four characters, written once at save time. It exists so a human can
     * tell two keys apart on the screen without the system being able to show
     * either of them. It is deliberately a separate column rather than something
     * derived on read, because deriving it would mean decrypting the secret to
     * render a list.
     *
     * ⚠ NOT business-scoped, on purpose. This is an infrastructure credential
     * for the server, the same kind of thing as the mail password, not tenant
     * data. A second business does not get its own Anthropic account, and
     * scoping it would invite one business to spend another's quota.
     *
     * ℹ One active row per provider is enforced by the partial unique index
     * below. Superseding a key is inserting a new row and clearing the old
     * one's `is_active`, so a rotation keeps its history.
     */
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('label')->nullable();
            $table->string('model')->nullable();
            $table->text('secret');
            $table->string('hint', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->string('verify_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credentials');
    }
};
